<?php
namespace Tests\Unit;

use App\Services\OperationalCompliance;
use App\Services\Ops\TransportFlow;
use App\Services\Ops\TransportSettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Business regressions for the operational boundary. All data lives in verified SQLite :memory:. */
class OpsBoundaryTest extends TransportFlowTest
{
    private function reject(callable $action, int $status): void
    {
        try { $action(); self::fail('Expected request rejection.'); }
        catch (ValidationException $e) { self::assertSame($status,$e->status); }
        catch (HttpException $e) { self::assertSame($status,$e->getStatusCode()); }
    }

    public function test_boundary_unscoped_paid_invoice_cannot_finish_two_manual_jobs(): void
    {
        $a=$this->ops->create(1,1,['title'=>'A','agreed_revenue'=>'1200']);
        $b=$this->ops->create(1,1,['title'=>'B','agreed_revenue'=>'1200']);
        foreach ([$a,$b] as $o) {
            DB::table('ops_operational_state')->where('order_id',$o->id)->update(['items'=>'[{"key":"ready","status":"completed"}]']);
            $this->ops->event(1,1,$o->id,['event_type'=>'delivered']);
        }
        $invoice=$this->fixtureInvoice($a);$this->fixturePayment($invoice,'1200.00');
        DB::table('ops_order_documents')->insert(['order_id'=>$b->id,'role'=>'revenue','invoice_id'=>$invoice]);
        foreach ([$a,$b] as $o) {
            $s=app(TransportSettlement::class)->read($o);
            self::assertFalse($s['can_finish']);self::assertSame('0.00',$s['billed_net']);
            self::assertSame('partially_closed',$this->ops->close(1,1,$o->id)->status);
        }
    }

    public function test_boundary_ops_financial_routes_are_absent_even_for_company_owner(): void
    {
        $this->book();
        foreach (['ops/orders/1/revenue-invoices','ops/orders/1/items','ops/templates','ops/pantheon','ops/pantheon/sync'] as $path) $this->reject(fn()=>$this->apiCall($path,'POST',['invoice_id'=>1,'write'=>true]),404);
        $before=DB::table('accounting_entries')->count();
        foreach (['agreed_revenue'=>'100','currency'=>'EUR','template_id'=>1] as $key=>$value) $this->reject(fn()=>$this->apiCall('ops/orders','POST',['title'=>'Invalid',$key=>$value]),422);
        self::assertSame($before,DB::table('accounting_entries')->count());
    }

    public function test_boundary_rounds_invoice_items_like_accounting(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);
        DB::table('ops_orders')->where('id',1)->update(['agreed_revenue'=>'0.02']);
        $invoice=$this->fixtureInvoice($this->order(),'0.02');
        DB::table('invoice_items')->where('invoice_id',$invoice)->update(['unit_price'=>'0.015']);
        $this->fixturePayment($invoice,'0.02');
        self::assertSame('0.02',$this->settlement()['billed_net']);
        self::assertNotContains('incomplete_billing',$this->settlement()['problems']);
    }

    public function test_boundary_completion_checks_checklist_and_pod(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);
        $invoice=$this->fixtureInvoice($this->order());$this->fixturePayment($invoice,'1200');
        self::assertFalse($this->settlement()['can_finish']);self::assertContains('checklist',$this->settlement()['problems']);
        self::assertContains('missing_pod',$this->settlement()['problems']);
        $w->update(['operational_checklist'=>[['key'=>'ready','status'=>'completed'],['key'=>'proof_of_delivery','status'=>'completed'],['key'=>'vehicle_return','status'=>'completed']]]);
        $this->ops->event(1,1,1,['event_type'=>'pod']);
        self::assertTrue($this->settlement()['can_finish']);
        $before=DB::table('accounting_entries')->count();
        self::assertSame('closed',$this->ops->close(1,1,1)->status);
        self::assertSame($before,DB::table('accounting_entries')->count());
        self::assertSame(0,DB::table('ops_order_documents')->whereNotNull('invoice_id')->count());
    }

    public function test_boundary_changed_evidence_requires_review_and_authorized_reopening(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);
        Schema::table('documents',function(Blueprint $t){$t->string('path')->nullable();$t->timestamps();});
        Storage::fake('local');Storage::disk('local')->put('documents/pod.txt','Original');
        DB::table('documents')->insert(['id'=>1,'name'=>'POD','path'=>'pod.txt','uploaded_by_user_id'=>1]);
        DB::table('ops_order_documents')->insert(['order_id'=>1,'role'=>'pod','document_id'=>1]);
        $hashes=app(OperationalCompliance::class)->documentHashes([1]);
        $w->update(['operational_checklist'=>[['key'=>'pod_check','status'=>'completed','required_documents'=>['POD'],'document_ids'=>[1],'document_hashes'=>$hashes],['key'=>'proof_of_delivery','status'=>'completed'],['key'=>'vehicle_return','status'=>'completed']]]);
        $invoice=$this->fixtureInvoice($this->order());$this->fixturePayment($invoice,'1200');
        self::assertSame('closed',$this->ops->close(1,1,1)->status);
        Storage::disk('local')->put('documents/pod.txt','Changed');
        self::assertSame('review_required',$this->settlement()['order']);self::assertFalse($this->settlement()['can_finish']);
        $this->reject(fn()=>$this->apiCall('ops/orders/1/reopen','POST',['revision'=>0,'reason'=>'Review proof']),409);
        $this->apiCall('ops/orders/1/reopen','POST',['revision'=>$this->order()->revision,'reason'=>'Review changed POD']);
        self::assertSame('in_progress',$this->order()->status);self::assertNull($this->order()->finished_at);
        self::assertSame(1,DB::table('compliance_audit')->where('action','reopened_for_review')->count());
    }

    public function test_boundary_new_sea_order_never_uses_road_template(): void
    {
        $this->apiCall('ops/checklist-versions','POST',['transport_type'=>'road','variant'=>'FTL','items'=>[['key'=>'road_only','label'=>'Road only']]]);
        $template=DB::table('ops_service_templates')->insertGetId(['company_id'=>1,'code'=>'SEA','name'=>'Sea','transport_type'=>'sea','created_by'=>1]);
        $order=$this->ops->create(1,1,['title'=>'Sea','template_id'=>$template]);
        self::assertNotContains('road_only',array_column(app(OperationalCompliance::class)->read($order)['items'],'key'));
    }

    public function test_boundary_flow_requires_checklist_revision_and_matching_variant(): void
    {
        $this->book();
        $data=['revision'=>0,'transport_type'=>'road','variant'=>'FTL','legs'=>[],'transit'=>[]];
        $this->reject(fn()=>$this->apiCall('ops/orders/1/transport-flow','PATCH',$data),422);
        $this->reject(fn()=>$this->apiCall('ops/orders/1/transport-flow','PATCH',$data+['checklist_revision'=>99]),409);
        foreach (['air','warehouse','customs'] as $mode) $this->reject(fn()=>$this->apiCall('ops/orders/1/transport-flow','PATCH',array_merge($data,['transport_type'=>$mode,'variant'=>'FCL','checklist_revision'=>0])),422);
        self::assertSame(0,DB::table('ops_transport_flows')->count());
    }

    public function test_boundary_schedule_rejects_end_before_start_on_create_and_patch(): void
    {
        $this->reject(fn()=>$this->apiCall('ops/orders','POST',['title'=>'Bad','planned_start_at'=>'2026-10-25','planned_end_at'=>'2026-10-24']),422);
        self::assertSame(0,DB::table('ops_orders')->count());
        $order=$this->ops->create(1,1,['title'=>'Valid','planned_start_at'=>'2026-10-25','planned_end_at'=>'2026-10-26']);
        $this->reject(fn()=>$this->apiCall('ops/orders/'.$order->id,'PATCH',['planned_end_at'=>'2026-10-24']),422);
        $this->reject(fn()=>$this->apiCall('ops/orders/'.$order->id,'PATCH',['planned_start_at'=>'2026-10-27']),422);
        self::assertStringStartsWith('2026-10-26',DB::table('ops_orders')->value('planned_end_at'));
    }

    public function test_boundary_sea_plus_road_services_preserve_completed_sea_tasks(): void
    {
        $this->book();$sea=['key'=>'sea','mode'=>'sea','variant'=>'FCL','from'=>'Nansha','to'=>'Rijeka','status'=>'planned','document_ids'=>[]];
        $this->flow(['transport_type'=>'sea','variant'=>'FCL','legs'=>[$sea]]);
        $ops=app(OperationalCompliance::class);$s=$ops->read($this->order());
        $ops->update(1,1,1,['revision'=>$s['revision'],'fingerprint'=>$s['fingerprint'],'items'=>[['key'=>'service_sea_shipping_line','status'=>'completed']]]);
        $road=['key'=>'road','mode'=>'road','variant'=>'FTL','from'=>'Rijeka','to'=>'Sarajevo','status'=>'planned','responsible_user_id'=>1,'due_date'=>'2026-10-27','document_ids'=>[]];
        $this->flow(['revision'=>1,'transport_type'=>'sea','variant'=>'FCL','legs'=>[$sea,$road]]);
        $s=$ops->read($this->order());
        self::assertSame('completed',collect($s['items'])->firstWhere('key','service_sea_shipping_line')['status']);
        self::assertSame('pending',collect($s['items'])->firstWhere('key','service_road_cmr')['status']);
        self::assertSame('sea',app(TransportFlow::class)->read($this->order())['transport_type']);
        self::assertSame(1,DB::table('ops_orders')->count());
        $road['to']='Zenica';$this->flow(['revision'=>2,'transport_type'=>'sea','variant'=>'FCL','legs'=>[$sea,$road]]);
        self::assertSame('completed',collect($ops->read($this->order())['items'])->firstWhere('key','service_sea_shipping_line')['status']);
    }

    public function test_boundary_customs_and_warehouse_generate_operational_proofs(): void
    {
        $this->book();$flow=app(TransportFlow::class);
        foreach (['customs'=>'service_c_declaration','warehouse'=>'service_c_receipt'] as $mode=>$key) {
            $tasks=$flow->tasks($mode,[['key'=>'c','mode'=>$mode,'from'=>'Rijeka','to'=>'Rijeka']]);
            self::assertNotEmpty(collect($tasks)->firstWhere('key',$key)['required_documents']);
        }
    }

    public function test_boundary_ops_permission_cannot_edit_catalogue_or_connector(): void
    {
        DB::table('accounting_permissions')->where('company_id',1)->where('user_id',1)->update(['abilities'=>'["ops"]']);
        $this->reject(fn()=>$this->apiCall('catalog','POST',['code'=>'BAD','name'=>'Bad','sale_price'=>100]),403);
        $this->reject(fn()=>$this->apiCall('integrations/ops-pantheon','POST',['sync_enabled'=>true]),403);
        $this->reject(fn()=>$this->apiCall('integrations/ops-pantheon/sync','POST',['write'=>true]),403);
    }

    public function test_boundary_started_service_cannot_disappear_from_history(): void
    {
        $this->book();
        $sea=['key'=>'sea','mode'=>'sea','from'=>'Nansha','to'=>'Rijeka','status'=>'in_progress','document_ids'=>[]];
        $this->flow(['transport_type'=>'sea','variant'=>'FCL','legs'=>[$sea]]);
        $this->reject(fn()=>$this->flow(['revision'=>1,'transport_type'=>'sea','variant'=>'FCL','legs'=>[]]),422);
        self::assertCount(1,app(TransportFlow::class)->read($this->order())['legs']);
    }

    public function test_post_a_load_rail_service_supports_container_variants_and_rail_evidence(): void
    {
        $this->book();
        $rail=['key'=>'rail','mode'=>'rail','variant'=>'FCL','from'=>'Rijeka','to'=>'Sarajevo','status'=>'planned','document_ids'=>[]];
        $flow=$this->flow(['transport_type'=>'rail','variant'=>'FCL','legs'=>[$rail]]);
        self::assertSame('rail',$flow['transport_type']);self::assertSame('FCL',$flow['variant']);
        $items=app(OperationalCompliance::class)->read($this->order())['items'];
        self::assertSame(['CIM / SMGS'],collect($items)->firstWhere('key','service_rail_rail_note')['required_documents']);
        $this->reject(fn()=>$this->flow(['revision'=>1,'transport_type'=>'rail','variant'=>'FTL','legs'=>[$rail]]),422);
    }
}
