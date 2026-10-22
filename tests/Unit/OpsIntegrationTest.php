<?php
namespace Tests\Unit;
use App\Models\User;
use App\Models\Role;
use App\Services\Ops\OpsPortal;
use App\Services\Ops\ExternalCargoReview;
use App\Services\Ops\OperationalSignals;
use App\Services\OperationalCompliance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** The parent verifies the effective SQLite :memory: connection before any fixture write. */
class OpsIntegrationTest extends TransportFlowTest
{
    protected function setUp(): void {
        parent::setUp();
        if((new \ReflectionMethod($this,$this->name()))->getDeclaringClass()->getName()!==self::class)return;
        (require database_path('migrations/2026_10_23_000001_link_ops_departments_and_participants.php'))->up();
        Schema::table('company_user',function(Blueprint $t){$t->timestamps();});
        Schema::table('documents',function(Blueprint $t){$t->string('path')->nullable();$t->string('type')->nullable();foreach(['load_id','vehicle_id','user_id','load_draft_id'] as $key)$t->unsignedBigInteger($key)->nullable();});
        Storage::fake('local');
        DB::table('users')->insert([['id'=>3,'name'=>'Unrelated customer'],['id'=>22,'name'=>'Sea operator']]);
        DB::table('company_user')->insert([['company_id'=>1,'user_id'=>1,'status'=>'active'],['company_id'=>1,'user_id'=>22,'status'=>'active']]);
        DB::table('accounting_permissions')->insert(['company_id'=>1,'user_id'=>22,'granted_by'=>1,'abilities'=>'["ops"]']);
        foreach([11=>[1,'provider-ci'],12=>[1,'provider-pl'],21=>[2,'customer-ci'],22=>[2,'customer-pl'],23=>[2,'replacement-ci'],31=>[3,'private-other-customer']] as $id=>[$owner,$name]){
            Storage::disk('local')->put('documents/'.$name.'.txt',$name);
            DB::table('documents')->insert(['id'=>$id,'name'=>$name,'path'=>$name.'.txt','uploaded_by_user_id'=>$owner,'type'=>str_contains($name,'pl')?'PACKING_LIST':'INVOICE']);
        }
    }
    private function actor(int $id): User {$u=User::findOrFail($id);$u->setRelation('role',new Role(['name'=>match($id){1=>'company',22=>'dispatcher',default=>'user'}]));return $u;}
    private function call(string $path,string $method='GET',array $body=[],int $actor=1): array {
        $r=Request::create('/api/'.$path,$method,[],[],[],['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json'],json_encode($body));$this->app->instance('request',$r);$r->setUserResolver(fn()=>$this->actor($actor));
        $route=$this->app['router']->getRoutes()->match($r);$r->setRouteResolver(fn()=>$route);[$class,$action]=explode('@',$route->getActionName());
        $controller=$this->app->make($class);$result=$class===\App\Http\Controllers\Api\CompanyMembershipController::class?$controller->$action($r,(int)$route->parameter('company_membership')):$controller->$action($r);
        return $result->getData(true);
    }
    private function denied(callable $fn,int $status): void {try{$fn();self::fail('Expected denial.');}catch(HttpException $e){self::assertSame($status,$e->getStatusCode(),$e->getMessage());}}
    private function external(): object {
        $record=$this->apiCall('ops/orders','POST',['title'=>'External Nansha delivery','source'=>'external','kind'=>'order','department'=>'sea','customer_link_type'=>'customer','customer_link_id'=>5,'operations'=>['delivery'],'document_ids'=>[11,12],'cargo'=>['weight_kg'=>'1000']])['data'];return DB::table('ops_orders')->find($record['id']);
    }
    private function invited(object $order): void {
        $invite=$this->call('transport-orders/'.$order->id.'/invite','POST')['data'];
        $this->call('transport-orders/'.$order->id.'/accept','POST',['revision'=>$invite['revision']],2);
    }
    private function received(object $order,array $ids=[11,12],int $operator=1): void {
        $revision=(int)DB::table('ops_operational_state')->where('order_id',$order->id)->value('revision');
        $this->call('transport-orders/'.$order->id.'/receive','POST',['revision'=>$revision,'ci_document_id'=>$ids[0],'pl_document_id'=>$ids[1]],$operator);
    }
    private function proposal(object $order,array $ids=[11,12],int $actor=1): array {
        $view=app(ExternalCargoReview::class)->read($order,$this->actor($actor));return ['action'=>'report','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'actual'=>['weight_kg'=>'1500'],'proposed_total'=>'1500.00','pricing_basis'=>'Actual documented weight','reason'=>'CI/PL differs from the external order.','ci_document_id'=>$ids[0],'pl_document_id'=>$ids[1],'documents_received'=>true];
    }
    public function test_linked_customer_accepts_only_one_order_without_company_membership(): void {
        $order=$this->external();$count=DB::table('company_user')->count();
        $invite=$this->call('transport-orders/'.$order->id.'/invite','POST')['data'];
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id,actor:2),403);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/accept','POST',['revision'=>$invite['revision']],3),404);
        $this->call('transport-orders/'.$order->id.'/accept','POST',['revision'=>$invite['revision']],2);
        self::assertSame($order->id,$this->call('transport-orders/'.$order->id,actor:2)['data']['order']['id']);
        self::assertSame($count,DB::table('company_user')->count());
        $second=$this->ops->register(1,1,['title'=>'Another customer order','source'=>'manual','operations'=>['delivery']]);
        $this->denied(fn()=>$this->call('transport-orders/'.$second->id,actor:2),403);
        $active=DB::table('ops_order_participants')->where('order_id',$order->id)->first();
        $this->call('transport-orders/'.$order->id.'/revoke','POST',['revision'=>$active->revision]);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id,actor:2),403);
        self::assertSame([],$this->call('transport-orders',actor:2)['data']);
    }
    public function test_expired_and_stale_invitations_cannot_be_accepted(): void {
        $order=$this->external();$invite=$this->call('transport-orders/'.$order->id.'/invite','POST')['data'];
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/accept','POST',['revision'=>99],2),409);
        DB::table('ops_order_participants')->where('order_id',$order->id)->update(['expires_at'=>now()->subMinute()]);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/accept','POST',['revision'=>$invite['revision']],2),422);
        $next=$this->call('transport-orders/'.$order->id.'/invite','POST')['data'];self::assertGreaterThan($invite['revision'],$next['revision']);
    }
    public function test_customer_supplies_files_only_for_their_accepted_order(): void {
        $order=$this->external();$this->invited($order);
        $this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>21,'role'=>'ci'],2);
        $this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>22,'role'=>'pl'],2);
        self::assertContains(21,array_column($this->call('transport-orders/'.$order->id,actor:2)['data']['documents'],'id'));
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>31,'role'=>'ci'],2),404);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>31,'role'=>'ci'],3),403);
        DB::table('documents')->where('id',23)->update(['user_id'=>2]);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>23,'role'=>'ci'],2),422);
    }
    public function test_receipt_and_external_price_change_need_operator_and_customer_confirmation(): void {
        $order=$this->external();$this->invited($order);$portal=app(OpsPortal::class);
        $this->denied(fn()=>$this->received($order,operator:2),403);
        $this->denied(fn()=>$portal->assertDocumentsReady($order),409);
        $this->received($order);self::assertSame('in_progress',$portal->documentState($order)['status']);
        $view=$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$this->proposal($order))['data'];self::assertSame('pending_customer',$view['review']['status']);
        $this->denied(fn()=>$this->ops->event(1,1,$order->id,['event_type'=>'loaded']),409);
        $decision=['action'=>'approve','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'reason'=>'Cargo data and total price accepted.'];
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$decision),403);
        $accepted=$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$decision,2)['data'];self::assertSame('accepted',$accepted['review']['status']);
        $order=DB::table('ops_orders')->find($order->id);self::assertEquals(1500,$order->agreed_revenue);self::assertEquals(1500,$portal->context($order)['intake']['cargo']['weight_kg']);self::assertEquals(1000,$portal->context($order)['intake']['original_cargo']['weight_kg']);
        self::assertTrue($portal->documentState($order)['review_complete']);$portal->assertDocumentsReady($order);self::assertSame(0,DB::table('accounting_entries')->count());
    }
    public function test_new_ci_upload_invalidates_old_receipt_and_customer_proposal(): void {
        $order=$this->external();$this->invited($order);$this->received($order);
        $view=$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$this->proposal($order))['data'];
        $this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>23,'role'=>'ci'],2);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/cargo-review','POST',['action'=>'approve','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'reason'=>'This old proposal must fail.'],2),409);
        self::assertFalse(app(OpsPortal::class)->documentState($order)['receipt_valid']);
        $this->received($order,[23,12]);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$this->proposal($order)),409);
    }
    public function test_assigned_operator_receives_documents_and_department_never_grants_accounting_rights(): void {
        $order=$this->external();$state=DB::table('ops_operational_state')->where('order_id',$order->id)->first();
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/assign-documents','POST',['revision'=>$state->revision,'responsible_user_id'=>22],22),403);
        $this->call('transport-orders/'.$order->id.'/assign-documents','POST',['revision'=>$state->revision,'responsible_user_id'=>22]);
        $this->denied(fn()=>$this->received($order),403);$this->received($order,operator:22);
        $id=DB::table('company_user')->where('user_id',22)->value('id');$path='company-memberships/'.$id;
        $this->call($path,'PATCH',['departments'=>['sea','customs']]);self::assertSame(['sea','customs'],app(\App\Services\Ops\OpsDepartments::class)->forUser(1,22));
        $this->denied(fn()=>$this->call($path,'PATCH',['departments'=>['accounting']],22),403);
        self::assertSame('["ops"]',DB::table('accounting_permissions')->where('user_id',22)->value('abilities'));
    }
    public function test_receiving_the_same_documents_again_requires_a_fresh_proposal(): void {
        $order=$this->external();$this->invited($order);$this->received($order);
        $view=$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$this->proposal($order))['data'];
        $this->received($order);
        $this->denied(fn()=>$this->call('transport-orders/'.$order->id.'/cargo-review','POST',['action'=>'approve','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'reason'=>'A previous proposal cannot be reused.'],2),409);
        self::assertFalse(app(OpsPortal::class)->documentState($order)['review_complete']);
    }
    public function test_document_download_is_immediately_denied_after_access_revocation(): void {
        $order=$this->external();$this->invited($order);
        $download=function()use($order){$r=Request::create('/api/transport-orders/'.$order->id.'/documents/11');$r->setUserResolver(fn()=>$this->actor(2));$route=$this->app['router']->getRoutes()->match($r);$r->setRouteResolver(fn()=>$route);return app(\App\Http\Controllers\Api\OpsPortalController::class)->download($r);};
        self::assertInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class,$download());
        $participant=DB::table('ops_order_participants')->where('order_id',$order->id)->first();
        $this->call('transport-orders/'.$order->id.'/revoke','POST',['revision'=>$participant->revision]);
        $this->denied($download,403);
    }
    public function test_additive_migration_can_be_retried_without_losing_departments_or_participants(): void {
        $order=$this->external();$this->invited($order);DB::table('company_user')->where('user_id',22)->update(['departments'=>'["sea"]']);
        (require database_path('migrations/2026_10_23_000001_link_ops_departments_and_participants.php'))->up();
        self::assertSame('["sea"]',DB::table('company_user')->where('user_id',22)->value('departments'));
        self::assertSame('active',DB::table('ops_order_participants')->where('order_id',$order->id)->value('status'));
    }
    public function test_booking_customer_uploads_and_assigned_operator_proposes_actual_weight(): void {
        Schema::table('loads',function(Blueprint $t){$t->string('status')->default('booked');$t->unsignedBigInteger('customer_user_id')->default(2);foreach(\App\Services\Ops\CargoReview::FIELDS as $field)$t->string($field)->nullable();$t->timestamps();});
        DB::table('loads')->where('id',3)->update(['weight_kg'=>'1000']);$w=$this->book();$order=$this->order();app(OpsPortal::class)->ensureDocumentOperation($order);
        $this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>21,'role'=>'ci'],2);$this->call('transport-orders/'.$order->id.'/documents','POST',['document_id'=>22,'role'=>'pl'],2);
        $state=DB::table('ops_operational_state')->where('order_id',$order->id)->first();$this->call('transport-orders/'.$order->id.'/assign-documents','POST',['revision'=>$state->revision,'responsible_user_id'=>22]);$this->received($order,[21,22],22);
        $service=app(\App\Services\Ops\CargoReview::class);$view=$service->read($order,$this->actor(22));self::assertTrue($view['can_report']);
        $proposal=['action'=>'report','revision'=>$view['revision'],'load_fingerprint'=>$view['load_fingerprint'],'actual'=>['weight_kg'=>'1500'],'proposed_total'=>'1500.00','pricing_basis'=>'Actual documented weight','reason'=>'Actual CI and PL differ from posted weight.','ci_document_id'=>21,'pl_document_id'=>22,'documents_received'=>true];
        $pending=$this->call('transport-orders/'.$order->id.'/cargo-review','POST',$proposal,22)['data'];self::assertTrue($pending['can_receive']);
        $this->call('transport-orders/'.$order->id.'/cargo-review','POST',['action'=>'approve','revision'=>$pending['revision'],'load_fingerprint'=>$pending['load_fingerprint'],'reason'=>'I accept the actual cargo and amended total.'],2);
        self::assertTrue(app(OpsPortal::class)->documentState($order)['review_complete']);self::assertEquals(1500,DB::table('loads')->where('id',3)->value('weight_kg'));
    }
    public function test_unreviewed_external_documents_block_checklist_progress_too(): void {
        $order=$this->external();$state=app(OperationalCompliance::class)->read($order);
        $this->denied(fn()=>app(OperationalCompliance::class)->update(1,$order->id,1,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'delivery','status'=>'in_progress']]]),409);
    }
    public function test_real_bookings_link_idempotently_and_keep_their_original_workspace(): void {
        $workspace=$this->book();$workspace->update(['accepted_offer_id'=>99]);$order=$this->order();
        $first=$this->apiCall('ops/sync-bookings','POST')['data'];$again=$this->apiCall('ops/sync-bookings','POST')['data'];
        self::assertSame(0,$first['created']);self::assertSame(0,$again['created']);self::assertSame(1,DB::table('ops_orders')->where('workspace_id',$workspace->id)->count());
        $read=app(OperationalCompliance::class)->read($order);self::assertSame('documents_received',$read['items'][0]['key']);
        self::assertSame(1,count(array_filter($read['items'],fn($x)=>$x['key']==='documents_received')));
        $source=$this->apiCall('ops/orders/'.$order->id)['data']['source_links'];self::assertSame(99,$source['accepted_offer_id']);self::assertSame($workspace->id,$source['workspace_id']);
    }
    public function test_warehouse_booking_is_not_receipt_and_completed_movement_is_canonical_evidence(): void {
        Schema::create('warehouse_movements',function(Blueprint $t){$t->id();$t->unsignedBigInteger('load_id');$t->string('direction');$t->string('status');$t->timestamp('completed_at')->nullable();$t->timestamps();});
        $workspace=$this->book();$workspace->update(['operational_checklist'=>[['key'=>'record_storage_receipt','status'=>'pending','plan_version'=>2,'responsible_user_id'=>1]]]);$order=$this->order();
        DB::table('warehouse_movements')->insert(['id'=>1,'load_id'=>3,'direction'=>'inbound','status'=>'booked']);
        self::assertSame('pending',app(OperationalSignals::class)->derive($order,$workspace->operational_checklist)[0]['status']);
        DB::table('warehouse_movements')->where('id',1)->update(['status'=>'completed','completed_at'=>now()]);
        $item=app(OperationalSignals::class)->derive($order,$workspace->operational_checklist)[0];self::assertSame('completed',$item['status']);self::assertSame('warehouse_movement',$item['confirmation_source']);self::assertSame(1,$item['source_id']);
        $timeline=app(OperationalSignals::class)->timeline($workspace,$order);self::assertTrue(collect($timeline['stages'])->firstWhere('key','warehouse_received')['complete']);self::assertFalse(collect($timeline['stages'])->firstWhere('key','pod')['complete']);
    }
    public function test_crm_follow_up_is_visible_only_to_its_real_owner_and_keeps_source_id(): void {
        $this->book();$order=$this->order();DB::table('ops_orders')->where('id',$order->id)->update(['crm_document_id'=>9]);
        DB::table('crm_follow_ups')->insert([['company_id'=>1,'crm_document_id'=>9,'due_on'=>now()->toDateString(),'note'=>'Confirm export documents','owner_user_id'=>1,'created_by'=>1],['company_id'=>1,'crm_document_id'=>9,'due_on'=>now()->toDateString(),'note'=>'Other operator follow-up','owner_user_id'=>22,'created_by'=>1]]);
        $rows=$this->apiCall('ops/today')['data'];$crm=array_values(array_filter($rows,fn($row)=>$row['kind']==='crm_follow_up'));
        self::assertCount(1,$crm);self::assertSame('Confirm export documents',$crm[0]['item']['label']);self::assertSame(1,$crm[0]['item']['source_id']);self::assertSame(2,DB::table('crm_follow_ups')->count());
    }
}
