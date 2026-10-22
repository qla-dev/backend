<?php

namespace Tests\Unit;

use App\Models\ShipmentWorkspace;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingPayments;
use App\Services\OperationalCompliance;
use App\Services\Ops\TransportFlow;
use App\Services\Ops\TransportSettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Reuses the existing in-memory Ops fixture and its integration regressions. */
class TransportFlowTest extends OpsWorkOrderTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('invoices', function (Blueprint $t) { $t->string('number')->nullable(); $t->string('currency')->default('BAM'); $t->decimal('subtotal',14,2)->default(0); $t->decimal('total',14,2)->default(0); $t->timestamps(); });
        Schema::create('company_user', fn (Blueprint $t) => [$t->id(),$t->unsignedBigInteger('company_id'),$t->unsignedBigInteger('user_id'),$t->string('status')]);
        (require database_path('migrations/2026_10_21_000001_create_operational_compliance.php'))->up();
        (require database_path('migrations/2026_10_22_000001_create_transport_flows.php'))->up();
        DB::table('accounting_settings')->insert(['company_id'=>1,'jurisdiction'=>'BA']);
        DB::table('accounting_periods')->insert(['id'=>1,'company_id'=>1,'name'=>'Test','starts_on'=>'2026-01-01','ends_on'=>'2026-12-31']);
        DB::table('accounting_accounts')->insert([['id'=>1,'company_id'=>1,'code'=>'200','name'=>'Receivable','kind'=>'asset','approved_by'=>1],['id'=>2,'company_id'=>1,'code'=>'100','name'=>'Bank','kind'=>'asset','approved_by'=>1]]);
        DB::table('accounting_permissions')->insert(['company_id'=>1,'user_id'=>1,'granted_by'=>1,'abilities'=>json_encode(['ops','setup','view','payments'])]);
    }

    public function test_close_remains_partial_until_delivery_cost_pod_and_payment_are_present(): void
    {
        // This inherited regression models a legacy order without the new opt-in checklist state.
        Schema::drop('ops_operational_state');
        parent::test_close_remains_partial_until_delivery_cost_pod_and_payment_are_present();
    }

    public function fixtureInvoice(object $order, string $amount = '1200.00', bool $post = true): int
    {
        $id = DB::table('invoices')->insertGetId(['company_id'=>1,'number'=>'DEMO-'.(DB::table('invoices')->count()+1),'direction'=>'outgoing','partner_id'=>1,'accounting_managed'=>true,'issuance_status'=>'issued','currency'=>'BAM','subtotal'=>$amount,'total'=>$amount,'posting_date'=>'2026-10-22']);
        DB::table('invoice_items')->insert(['invoice_id'=>$id,'workspace_id'=>$order->workspace_id,'quantity'=>1,'unit_price'=>$amount]);
        if (!$order->workspace_id) DB::table('ops_order_documents')->insert(['order_id'=>$order->id,'role'=>'revenue','invoice_id'=>$id]);
        if ($post) $this->app->make(AccountingLedger::class)->post(1,1,['event_key'=>'invoice:'.$id,'posting_date'=>'2026-10-22','description'=>'Test invoice','invoice_id'=>$id,'lines'=>[['account_id'=>2,'credit'=>$amount,'debit'=>'0'],['account_id'=>1,'debit'=>$amount,'credit'=>'0']]]);
        return $id;
    }

    public function fixturePayment(int $invoice, string $amount): object
    {
        $bank = DB::table('accounting_bank_transactions')->insertGetId(['company_id'=>1,'reference'=>'DEMO-PAY-'.(DB::table('accounting_bank_transactions')->count()+1),'bank_account'=>'DEMO','partner_name'=>'Demo customer','partner_id'=>1,'direction'=>'incoming','transaction_date'=>'2026-10-22','amount'=>$amount,'currency'=>'BAM','exchange_rate'=>1,'exchange_source'=>'base','created_by'=>1]);
        return $this->app->make(AccountingPayments::class)->allocate(1,1,['invoice_id'=>$invoice,'bank_transaction_id'=>$bank,'amount'=>$amount,'bank_account_id'=>2,'request_key'=>'payment-'.$bank]);
    }

    protected function settlement(): array { return $this->app->make(TransportSettlement::class)->read($this->order()); }
    protected function flow(array $changes = []): array
    {
        return $this->app->make(TransportFlow::class)->save($this->order(),1,array_merge(['checklist_revision'=>(int)DB::table('ops_operational_state')->where('order_id',$this->order()->id)->value('revision'),'revision'=>0,'transport_type'=>'road','variant'=>'FTL','legs'=>[],'transit'=>[]],$changes));
    }
    public function test_delivered_without_invoice_remains_open(): void
    {
        $w=$this->book(); $w->update(['status'=>'completed','operational_checklist'=>[['key'=>'ready','status'=>'completed'],['key'=>'proof_of_delivery','status'=>'completed'],['key'=>'vehicle_return','status'=>'completed']]]);
        self::assertSame('uninvoiced',$this->settlement()['payment']);
        self::assertSame('partially_closed',$this->ops->close(1,1,$this->order()->id)->status);
    }
    public function test_prepayment_without_delivery_does_not_finish(): void
    {
        $this->book();$id=$this->fixtureInvoice($this->order());$this->fixturePayment($id,'1200.00');
        self::assertSame('paid',$this->settlement()['payment']);self::assertFalse($this->settlement()['can_finish']);
    }
    public function test_multiple_invoices_partial_payments_and_unposted_invoice(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);
        $first=$this->fixtureInvoice($this->order(),'800.00');$second=$this->fixtureInvoice($this->order(),'400.00');
        $this->fixturePayment($first,'800.00');$this->fixturePayment($second,'100.00');
        self::assertSame('partial',$this->settlement()['payment']);self::assertFalse($this->settlement()['can_finish']);
        $this->fixturePayment($second,'300.00');self::assertSame('paid',$this->settlement()['payment']);self::assertFalse($this->settlement()['can_finish']); // Operational checklist/POD remain pending.
        $this->fixtureInvoice($this->order(),'50.00',false);self::assertSame('unposted',$this->settlement()['payment']);self::assertFalse($this->settlement()['can_finish']);
    }
    public function test_underbilling_does_not_complete_a_job(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);$id=$this->fixtureInvoice($this->order(),'10.00');$this->fixturePayment($id,'10.00');
        self::assertContains('incomplete_billing',$this->settlement()['problems']);self::assertFalse($this->settlement()['can_finish']);
    }
    public function test_reversal_reopens_finance_preserves_delivery_and_is_idempotent(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);$order=$this->order();$this->flow();
        $id=$this->fixtureInvoice($order);$allocation=$this->fixturePayment($id,'1200.00');
        $w->update(['operational_checklist'=>[['key'=>'ready','status'=>'completed'],['key'=>'proof_of_delivery','status'=>'completed'],['key'=>'vehicle_return','status'=>'completed']]]);
        DB::table('ops_order_items')->where('order_id',$order->id)->where('item_type','cost')->update(['actual_price'=>800]);
        $this->ops->event(1,1,$order->id,['event_type'=>'pod']);
        self::assertSame('closed',$this->ops->close(1,1,$order->id)->status);
        $revision=$this->order()->revision;$this->ops->close(1,1,$order->id);self::assertSame($revision,$this->order()->revision);
        $payments=$this->app->make(AccountingPayments::class);
        $payments->reverseAllocation(1,1,$allocation->id,'2026-10-22','Wrong allocation');
        self::assertSame('partially_closed',$this->order()->status);self::assertNull($this->order()->finished_at);self::assertSame('delivered',$this->settlement()['transport']);
        self::assertSame('0.00',$payments->allocated('invoice_id',$id));
        $payments->reverseAllocation(1,1,$allocation->id,'2026-10-22','Retry correction');
        self::assertSame(1,DB::table('accounting_entries')->where('event_key','allocation-reversal:'.$allocation->id)->count());
        self::assertSame(1,DB::table('accounting_audit_events')->where('action','ops_finance_reopened')->count());
    }
    public function test_domestic_flow_has_no_customs_block_and_uses_one_shipment_list(): void
    {
        $this->book();$this->flow();$state=$this->app->make(OperationalCompliance::class)->read($this->order());
        self::assertSame('shipment_workspace',$state['source']);self::assertFalse(collect($state['items'])->firstWhere('key','transit')['applicable']);
        self::assertTrue(collect($state['items'])->firstWhere('key','delivery')['system']);
        self::assertSame(count($state['items']),count(ShipmentWorkspace::first()->operational_checklist));
    }
    public function test_system_delivery_cannot_be_manually_confirmed(): void
    {
        $this->book();$this->flow();$ops=$this->app->make(OperationalCompliance::class);$order=$this->order();$state=$ops->read($order);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $ops->update(1,$order->id,1,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'delivery','status'=>'completed']]]);
    }
    public function test_multimodal_requires_two_legs(): void
    {
        $this->book();$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->flow(['variant'=>null,'transport_type'=>'multimodal']);
    }
    public function test_partial_leg_delivery_blocks_paid_delivered_workspace(): void
    {
        $w=$this->book();$w->update(['status'=>'completed']);$this->flow(['variant'=>null,'transport_type'=>'multimodal','legs'=>[['key'=>'road','mode'=>'road','from'=>'Sarajevo','to'=>'Ploče','status'=>'planned'],['key'=>'sea','mode'=>'sea','from'=>'Ploče','to'=>'Hamburg','status'=>'planned']]]);
        $id=$this->fixtureInvoice($this->order());$this->fixturePayment($id,'1200.00');self::assertFalse($this->settlement()['can_finish']);
    }
    public function test_flow_rejects_stale_revision(): void
    {
        $this->book();$this->flow();$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);$this->flow();
    }
    public function test_read_does_not_create_flow_or_change_history(): void
    {
        $this->book();$this->settlement();self::assertSame(0,DB::table('ops_transport_flows')->count());
    }
    protected function apiCall(string $path, string $method = 'GET', array $data = [], int $actor = 1): array
    {
        $request=\Illuminate\Http\Request::create('/api/accounting/1/'.$path,$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],json_encode($data));
        $this->app->instance('request',$request);
        $user=\App\Models\User::findOrFail($actor);$user->setRelation('role',new \App\Models\Role(['name'=>'company']));$request->setUserResolver(fn()=>$user);
        $route=$this->app['router']->getRoutes()->match($request);$request->setRouteResolver(fn()=>$route);
        [$controller,$function]=explode('@',$route->getActionName());
        return $this->app->make($controller)->$function($request)->getData(true);
    }
    public function test_api_templates_are_pinned_and_non_members_cannot_update(): void
    {
        $this->book();
        $one=$this->apiCall('ops/checklist-versions','POST',['transport_type'=>'road','variant'=>'FTL','items'=>[['key'=>'custom_review','label'=>'First review','phase'=>'booking']]]);
        $this->flow();
        $two=$this->apiCall('ops/checklist-versions','POST',['transport_type'=>'road','variant'=>'FTL','items'=>[['key'=>'different_review','label'=>'Second review','phase'=>'booking']]]);
        $state=$this->apiCall('ops/orders/1/compliance')['data']['checklist'];
        self::assertSame(1,$state['template_version']);self::assertContains('custom_review',array_column($state['items'],'key'));self::assertNotContains('different_review',array_column($state['items'],'key'));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $this->apiCall('ops/orders/1/transport-flow','PATCH',['revision'=>1,'transport_type'=>'road','legs'=>[],'transit'=>[]],2);
    }
    public function test_api_requires_evidence_and_detects_modified_document(): void
    {
        $this->book();$this->flow();
        Schema::table('documents',function(Blueprint $t){$t->string('path')->nullable();$t->timestamps();});
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('documents/proof.txt','Original evidence');
        DB::table('documents')->insert(['id'=>1,'name'=>'Proof','path'=>'proof.txt','uploaded_by_user_id'=>1]);
        DB::table('ops_order_documents')->insert(['order_id'=>1,'role'=>'attachment','document_id'=>1]);
        $state=$this->apiCall('ops/orders/1/compliance')['data']['checklist'];
        $this->apiCall('ops/orders/1/checklist','PATCH',['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'leg_main','status'=>'completed','document_ids'=>[1]]]]);
        $state=$this->apiCall('ops/orders/1/compliance')['data']['checklist'];self::assertSame('completed',collect($state['items'])->firstWhere('key','leg_main')['status']);
        \Illuminate\Support\Facades\Storage::disk('local')->put('documents/proof.txt','Changed evidence');
        $state=$this->apiCall('ops/orders/1/compliance')['data']['checklist'];self::assertTrue(collect($state['items'])->firstWhere('key','leg_main')['needs_review']);
        self::assertSame('pending',collect($state['items'])->firstWhere('key','leg_main')['status']);
    }
    public function test_transit_stays_visible_and_editable_after_commercial_completion(): void
    {
        $this->book();$this->flow(['transit'=>[['key'=>'t1','status'=>'draft','document_ids'=>[]]]]);
        DB::table('ops_orders')->where('id',1)->update(['status'=>'closed']);
        $data=['checklist_revision'=>1,'revision'=>1,'transport_type'=>'road','variant'=>'FTL','legs'=>[],'transit'=>[['key'=>'t1','status'=>'draft','next_action'=>'Provjeriti razduženje','document_ids'=>[]]]];
        $this->apiCall('ops/orders/1/transport-flow','PATCH',$data);
        self::assertSame('closed',$this->order()->status);self::assertSame('Provjeriti razduženje',$this->apiCall('ops/orders/1')['data']['flow']['transit'][0]['next_action']);
    }
}
