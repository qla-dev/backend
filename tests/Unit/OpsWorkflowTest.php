<?php
namespace Tests\Unit;

use App\Models\User;
use App\Models\Load;
use App\Models\Offer;
use App\Models\Role;
use App\Services\Ops\CargoReview;
use App\Services\Ops\OperationsPlan;
use App\Services\Ops\TransportSettlement;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Parent verifies the effective SQLite :memory: connection before every fixture write. */
class OpsWorkflowTest extends TransportFlowTest
{
    public function test_workflow_external_inquiry_requires_owned_file_and_confirmation(): void
    {
        Schema::table('documents',function(Blueprint $t){$t->unsignedBigInteger('load_id')->nullable();$t->unsignedBigInteger('vehicle_id')->nullable();$t->unsignedBigInteger('user_id')->nullable();$t->unsignedBigInteger('load_draft_id')->nullable();});
        DB::table('documents')->insert(['id'=>7,'name'=>'External CMR','uploaded_by_user_id'=>1]);
        $payload=['title'=>'External Nansha shipment','kind'=>'inquiry','source'=>'external','department'=>'sea','external_reference'=>'EXT-2026-7','document_ids'=>[7],'cmr_document_id'=>7,'operations'=>['goods_documents','cmr','delivery'],'customer_link_type'=>'customer','customer_link_id'=>5,'cargo'=>['weight_kg'=>1800],'route'=>['from'=>'Nansha','to'=>'Rijeka']];
        $created=$this->apiCall('ops/orders','POST',$payload)['data'];$id=$created['id'];$service=app(\App\Services\Ops\OpsOrders::class);$order=DB::table('ops_orders')->find($id);
        self::assertNull($order->workspace_id);self::assertSame('Customer d.o.o.',$order->customer_name);
        self::assertSame('inquiry',$service->intake($order)['kind']);self::assertSame('external',$service->intake($order)['source']);
        self::assertSame(['goods_documents','cmr','delivery'],array_column(json_decode(DB::table('ops_operational_state')->where('order_id',$id)->value('items'),true),'key'));
        self::assertSame('cmr',DB::table('ops_order_documents')->where('order_id',$id)->value('role'));
        self::assertSame([],$this->apiCall('ops/operational-overview')['data']);
        self::assertSame([],$this->apiCall('ops/today')['data']);
        $this->reject(fn()=>$service->event(1,1,$id,['event_type'=>'loaded']),422);
        $this->reject(fn()=>$service->close(1,1,$id),422);
        $this->reject(fn()=>$service->confirmInquiry(1,1,$id,99),409);
        $confirmed=$this->apiCall('ops/orders/'.$id.'/confirm','POST',['revision'=>(int)$order->revision])['data'];
        self::assertSame('order',$service->intake((object)$confirmed)['kind']);self::assertNotEmpty($this->apiCall('ops/operational-overview')['data']);$service->event(1,1,$id,['event_type'=>'loaded']);
        self::assertSame(1,DB::table('ops_events')->where('order_id',$id)->where('event_type','loaded')->count());
        self::assertSame(0,DB::table('accounting_entries')->count());
    }

    public function test_workflow_external_import_rejects_foreign_file_and_marketplace_source(): void
    {
        DB::table('documents')->insert(['id'=>8,'name'=>'Other company file','uploaded_by_user_id'=>2]);
        $s=app(\App\Services\Ops\OpsOrders::class);
        $this->reject(fn()=>$s->register(1,1,['title'=>'Unsafe import','source'=>'external','document_ids'=>[8]]),403);
        $this->reject(fn()=>$s->register(1,1,['title'=>'Missing file','source'=>'external','document_ids'=>[]]),422);
        self::assertSame(0,DB::table('ops_orders')->count());
        try{$this->apiCall('ops/orders','POST',['title'=>'Forged booking','source'=>'marketplace']);self::fail('Must reject forged marketplace source.');}catch(\Illuminate\Validation\ValidationException $e){self::assertArrayHasKey('source',$e->errors());}
    }

    public function test_workflow_task_serialization_never_grants_whole_provider_checklist(): void
    {
        $w=$this->book();$w->operational_checklist=[['key'=>'mine','status'=>'pending','responsible_user_id'=>1],['key'=>'other_provider','status'=>'pending','responsible_user_id'=>2],['key'=>'shipping_instructions','status'=>'pending','responsible_user_id'=>2,'audience'=>'customer'],['key'=>'foreign_service','status'=>'pending','responsible_user_id'=>1,'provider_company_id'=>99]];
        $result=app(\App\Services\Ops\OpsTaskAccess::class)->visibleWorkspace($this->user(1),$w,$w->operational_checklist);
        self::assertContains('mine',array_column($result,'key'));self::assertNotContains('other_provider',array_column($result,'key'));self::assertNotContains('foreign_service',array_column($result,'key'));self::assertNotContains('shipping_instructions',array_column($result,'key'));
        $request=\Illuminate\Http\Request::create('/api/shipment-workspaces/1');$request->setUserResolver(fn()=>$this->user(1));
        $json=(new \App\Http\Resources\EntityResource($w))->resolve($request);
        self::assertSame(array_column($result,'key'),array_column($json['operational_checklist'],'key'));
        $load=(new Load())->forceFill(['id'=>3]);$load->setRelation('shipmentWorkspace',$w);$nested=(new \App\Http\Resources\EntityResource($load))->resolve($request);
        self::assertSame(array_column($result,'key'),array_column($nested['shipment_workspace']['operational_checklist'],'key'));
        $projection=app(\App\Services\Ops\OpsTaskAccess::class)->project($this->user(1),$this->order(),['items'=>[['key'=>'foreign_service','responsible_user_id'=>1,'provider_company_id'=>99]]]);self::assertSame([],$projection['items']);
    }

    public function test_workflow_selected_external_operations_survive_transport_configuration(): void
    {
        $record=$this->apiCall('ops/orders','POST',['title'=>'External operations plan','source'=>'manual','kind'=>'order','department'=>'sea','operations'=>['goods_documents','cmr','delivery']])['data'];$id=$record['id'];
        $state=$this->apiCall('ops/orders/'.$id.'/compliance')['data']['checklist'];
        $this->apiCall('ops/orders/'.$id.'/transport-flow','PATCH',['revision'=>0,'checklist_revision'=>$state['revision'],'transport_type'=>'sea','variant'=>'FCL','legs'=>[['key'=>'sea','mode'=>'sea','from'=>'Nansha','to'=>'Rijeka','status'=>'planned','document_ids'=>[]]],'transit'=>[]]);
        $items=json_decode(DB::table('ops_operational_state')->where('order_id',$id)->value('items'),true);
        self::assertEqualsCanonicalizing(['goods_documents','cmr','delivery'],array_column($items,'key'));
        $view=$this->apiCall('ops/orders/'.$id.'/compliance')['data'];self::assertArrayHasKey('pickup',$view['optional_catalog']);self::assertArrayNotHasKey('cargo_documents',$view['optional_catalog']);
        $this->apiCall('ops/orders/'.$id.'/optional-tasks','POST',['revision'=>$view['checklist']['revision'],'fingerprint'=>$view['checklist']['fingerprint'],'key'=>'pickup','reason'=>'Pickup coordination requested after document review.']);
        self::assertContains('pickup',array_column(json_decode(DB::table('ops_operational_state')->where('order_id',$id)->value('items'),true),'key'));
    }

    public function test_workflow_document_scan_prefills_only_and_never_creates_an_order(): void
    {
        Schema::table('documents',fn(Blueprint $t)=>$t->string('path')->nullable());Storage::fake('local');Storage::disk('local')->put('documents/external.txt','Synthetic external order: machinery 1800 kg');
        DB::table('documents')->insert(['id'=>7,'name'=>'External order.txt','path'=>'external.txt','uploaded_by_user_id'=>1]);
        $this->app['config']->set('services.openrouter.api_key','test-placeholder');
        $mock=\Mockery::mock(\App\Services\OpenRouterLoadScanner::class);$mock->shouldReceive('scan')->once()->with(\Mockery::on(fn($images)=>$images[0]['filename']==='External order.txt'&&$images[0]['mimeType']==='text/plain'))->andReturn(['title'=>'Machinery shipment','weightKg'=>1800,'pickupCity'=>'Rijeka']);$this->app->instance(\App\Services\OpenRouterLoadScanner::class,$mock);
        $result=$this->apiCall('ops/intake-scan','POST',['document_id'=>7])['data'];self::assertTrue($result['requires_review']);self::assertSame(1800,$result['scan']['weightKg']);self::assertSame(0,DB::table('ops_orders')->count());
        DB::table('documents')->where('id',7)->update(['uploaded_by_user_id'=>2]);$this->reject(fn()=>$this->apiCall('ops/intake-scan','POST',['document_id'=>7]),404);
    }

    public function test_workflow_verification_uses_existing_company_and_headquarters_without_creating_records(): void
    {
        if(!Schema::hasColumn('companies','country_code'))Schema::table('companies',fn(Blueprint $t)=>$t->string('country_code')->nullable());
        DB::table('companies')->where('id',1)->update(['country_code'=>'HR']);
        $before=DB::table('companies')->count();$result=$this->apiCall('country-profile')['data'];
        self::assertSame(1,$result['company']['id']);self::assertSame('HR',$result['default_country_code']);
        self::assertSame($before,DB::table('companies')->count());self::assertSame(0,DB::table('company_country_profiles')->count());
    }

    private function fixture(): void
    {
        Schema::table('loads',function(Blueprint $t){$t->string('status')->default('booked');$t->unsignedBigInteger('customer_user_id')->default(2);foreach(CargoReview::FIELDS as $field)$t->string($field)->nullable();$t->timestamps();});
        Schema::table('documents',fn(Blueprint $t)=>$t->string('path')->nullable());
        Storage::fake('local');
        foreach([1=>'CI',2=>'PL'] as $id=>$name){Storage::disk('local')->put('documents/'.$name.'.txt',$name.' original');DB::table('documents')->insert(['id'=>$id,'name'=>$name,'path'=>$name.'.txt','uploaded_by_user_id'=>1]);}
        DB::table('loads')->where('id',3)->update(['weight_kg'=>'1000','goods_type'=>'General cargo']);
        $w=$this->book();$w->update(['load_snapshot'=>['title'=>'Original booking','transport_type'=>'road','weight_kg'=>'1000'],'offer_snapshot'=>['id'=>99,'amount'=>'1200.00'],'operational_checklist'=>[['key'=>'cargo_documents','status'=>'pending','controlled'=>true,'plan_version'=>2,'responsible_user_id'=>1]]]);
        foreach([1,2] as $id)DB::table('ops_order_documents')->insert(['order_id'=>$this->order()->id,'document_id'=>$id,'role'=>'attachment']);
    }
    private function user(int $id): User {$u=User::findOrFail($id);$u->setRelation('role',new Role(['name'=>$id===1?'company':'user']));return $u;}
    private function proposal(array $replace=[]): array
    {
        $view=app(CargoReview::class)->read($this->order(),$this->user(1));
        return array_replace(['action'=>'report','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'ci_document_id'=>1,'pl_document_id'=>2,'documents_received'=>true,
            'actual'=>['weight_kg'=>'1500'],'proposed_total'=>'1500.00','pricing_basis'=>'1500 kg at the agreed weight rate','reason'=>'Actual weight differs from posted cargo.'],$replace);
    }
    private function reject(callable $fn,int $code): void {try{$fn();self::fail('Expected rejection.');}catch(HttpException $e){self::assertSame($code,$e->getStatusCode());}}

    public function test_workflow_customer_accepts_actual_weight_and_price_atomically(): void
    {
        $this->fixture();$service=app(CargoReview::class);$proposal=$this->proposal();$offer=DB::table('shipment_workspace')->value('offer_snapshot');
        $view=$service->act($this->order(),$this->user(1),$proposal);
        self::assertSame('pending_customer',$view['review']['status']);self::assertSame('1000',DB::table('loads')->where('id',3)->value('weight_kg'));
        self::assertEquals(1200,DB::table('shipment_workspace')->value('agreed_amount'));
        $this->reject(fn()=>$service->assertUnblocked(3),409);
        self::assertContains('cargo_review',app(TransportSettlement::class)->read($this->order())['problems']);
        $accepted=$service->act($this->order(),$this->user(2),['action'=>'approve','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'reason'=>'CI and PL checked; full proposal accepted.']);
        self::assertSame('accepted',$accepted['review']['status']);self::assertEquals(1500,DB::table('loads')->where('id',3)->value('weight_kg'));
        self::assertEquals(1500,DB::table('shipment_workspace')->value('agreed_amount'));self::assertEquals(1500,$this->order()->agreed_revenue);
        self::assertSame($offer,DB::table('shipment_workspace')->value('offer_snapshot'));
        self::assertEquals(1000,\App\Models\ShipmentWorkspace::first()->load_snapshot['weight_kg']);
        self::assertEquals(1500,\App\Models\ShipmentWorkspace::first()->load_snapshot['actual_cargo']['weight_kg']);
        self::assertSame('cargo_reconciliation',\App\Models\ShipmentWorkspace::first()->additional_charges[0]['source']);
        self::assertSame(0,DB::table('accounting_entries')->count());
        $service->assertUnblocked(3);
        $this->reject(fn()=>$service->act($this->order(),$this->user(2),['action'=>'approve','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'reason'=>'Replay should not apply twice.']),409);
    }

    public function test_workflow_provider_cannot_accept_own_price_proposal(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$s->act($this->order(),$this->user(1),$this->proposal());
        $this->reject(fn()=>$s->act($this->order(),$this->user(1),['action'=>'approve','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'Provider cannot approve.']),403);
    }

    public function test_workflow_changed_evidence_or_cargo_prevents_stale_acceptance(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$s->act($this->order(),$this->user(1),$this->proposal());
        Storage::disk('local')->put('documents/CI.txt','Changed invoice');
        $this->reject(fn()=>$s->act($this->order(),$this->user(2),['action'=>'approve','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'Do not approve changed evidence.']),409);
        self::assertEquals(1200,DB::table('shipment_workspace')->value('agreed_amount'));self::assertEquals(1000,DB::table('loads')->where('id',3)->value('weight_kg'));
    }

    public function test_workflow_rejection_keeps_the_shipment_blocked_and_original_price(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$s->act($this->order(),$this->user(1),$this->proposal());
        $v=$s->act($this->order(),$this->user(2),['action'=>'reject','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'The proposed rate is not accepted.']);
        self::assertSame('rejected',$v['review']['status']);$this->reject(fn()=>$s->assertUnblocked(3),409);
        $this->reject(fn()=>$this->ops->event(1,1,$this->order()->id,['event_type'=>'loaded']),409);
        self::assertEquals(1200,DB::table('shipment_workspace')->value('agreed_amount'));
    }

    public function test_workflow_price_decrease_and_price_only_change_require_customer_acceptance(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$s->act($this->order(),$this->user(1),$this->proposal(['actual'=>['weight_kg'=>'1000'],'proposed_total'=>'1100.00']));
        self::assertSame([],$v['review']['changes']);self::assertSame('pending_customer',$v['review']['status']);
        $s->act($this->order(),$this->user(2),['action'=>'approve','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'Lower agreed total accepted.']);
        self::assertEquals(1100,DB::table('shipment_workspace')->value('agreed_amount'));self::assertSame('-100.00',\App\Models\ShipmentWorkspace::first()->additional_charges[0]['rate']);
    }

    public function test_workflow_matches_can_confirm_documents_without_a_price_amendment(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$s->act($this->order(),$this->user(1),$this->proposal(['actual'=>['weight_kg'=>'1000'],'proposed_total'=>'1200.00']));
        self::assertSame('verified',$v['review']['status']);$s->assertUnblocked(3);self::assertFalse($v['can_confirm']);
    }

    public function test_workflow_booking_plan_is_short_assigned_and_requirement_driven(): void
    {
        $load=(new Load())->forceFill(['transport_type'=>'sea','customer_user_id'=>2,'requires_adr'=>true]);$offer=(new Offer())->forceFill(['created_by_user_id'=>1]);$offer->id=42;
        $items=app(OperationsPlan::class)->booking($load,$offer,1);
        self::assertLessThanOrEqual(7,count($items));self::assertSame(2,collect($items)->firstWhere('key','shipping_instructions')['responsible_user_id']);
        self::assertSame(1,collect($items)->firstWhere('key','final_bill_of_lading')['responsible_user_id']);
        self::assertTrue(collect($items)->contains('key','dangerous_goods_check'));
        self::assertFalse(collect($items)->contains('key','assign_driver_and_vehicle'));
        self::assertFalse(collect($items)->contains('key','vehicle_registrations'));
        self::assertSame(42,$items[0]['accepted_offer_id']);
    }

    public function test_workflow_changed_documents_after_acceptance_restore_the_hold(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$s->act($this->order(),$this->user(1),$this->proposal());
        $s->act($this->order(),$this->user(2),['action'=>'approve','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'Checked and accepted.']);
        Storage::disk('local')->put('documents/PL.txt','Changed packing list after acceptance');
        $this->reject(fn()=>$s->assertUnblocked(3),409);
        self::assertSame('review_required',$s->read($this->order(),$this->user(2))['review']['status']);
    }

    public function test_workflow_price_decrease_requires_accounting_to_reconcile_existing_invoice(): void
    {
        $this->fixture();$this->fixtureInvoice($this->order());$s=app(CargoReview::class);
        $v=$s->act($this->order(),$this->user(1),$this->proposal(['actual'=>['weight_kg'=>'1000'],'proposed_total'=>'1100.00']));
        $s->act($this->order(),$this->user(2),['action'=>'approve','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'Lower price accepted.']);
        self::assertContains('billing_amendment_review',app(TransportSettlement::class)->read($this->order())['problems']);
        self::assertEquals(1200,DB::table('invoices')->value('total'),'The amendment never rewrites a posted invoice.');
    }

    public function test_workflow_unrelated_user_cannot_read_or_approve_the_proposal(): void
    {
        $this->fixture();DB::table('users')->insert(['id'=>25,'name'=>'Other company user']);$u=User::findOrFail(25);$u->setRelation('role',new Role(['name'=>'company']));
        $this->reject(fn()=>app(CargoReview::class)->read($this->order(),$u),403);
        $this->reject(fn()=>$this->apiCall('ops/orders/'.$this->order()->id.'/checklist','PATCH',['revision'=>0,'fingerprint'=>app(\App\Services\OperationalCompliance::class)->read($this->order())['fingerprint'],'items'=>[['key'=>'cargo_documents','status'=>'completed']]]),422);
    }

    public function test_workflow_customer_decision_does_not_require_provider_only_receipt_fields(): void
    {
        $this->fixture();$s=app(CargoReview::class);$v=$this->apiCall('ops/orders/'.$this->order()->id.'/cargo-review','POST',$this->proposal())['data'];
        $decision=\Illuminate\Support\Facades\Validator::make(['action'=>'approve','revision'=>$v['revision'],'load_fingerprint'=>$v['load_fingerprint'],'reason'=>'The full amendment is accepted.'],CargoReview::rules())->validate();
        self::assertArrayNotHasKey('documents_received',$decision);
        self::assertSame('accepted',$s->act($this->order(),$this->user(2),$decision)['review']['status']);
        self::assertTrue(\Illuminate\Support\Facades\Validator::make($this->proposal(['documents_received'=>false]),CargoReview::rules())->fails());
    }
}
