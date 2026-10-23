<?php
namespace Tests\Unit;

use App\Models\User;
use App\Models\Role;
use App\Services\Ops\SupplyChain;
use App\Services\Ops\OpsPortal;
use App\Services\OperationalCompliance;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** All fixture writes follow the parent's verified SQLite :memory: connection. */
class OpsSupplyChainTest extends TransportFlowTest
{
    protected function setUp(): void {
        parent::setUp();if((new \ReflectionMethod($this,$this->name()))->getDeclaringClass()->getName()!==self::class)return;
        (require database_path('migrations/2026_10_23_000001_link_ops_departments_and_participants.php'))->up();
        Schema::table('users',function(Blueprint $t){$t->string('email')->nullable();$t->timestamp('email_verified_at')->nullable();});
        DB::table('users')->insert([['id'=>3,'name'=>'Exporter contact','email'=>'exporter@example.test','email_verified_at'=>now()],['id'=>4,'name'=>'Another supplier','email'=>'other@example.test','email_verified_at'=>now()]]);
        Schema::table('documents',function(Blueprint $t){$t->string('path')->nullable();$t->string('type')->nullable();foreach(['load_id','vehicle_id','user_id','load_draft_id'] as $k)$t->unsignedBigInteger($k)->nullable();});Storage::fake('local');
        foreach([1,2,3,4] as $id){Storage::disk('local')->put('documents/'.$id.'.txt','Synthetic '.$id);DB::table('documents')->insert(['id'=>$id,'name'=>'File '.$id,'path'=>$id.'.txt','uploaded_by_user_id'=>$id,'type'=>'INVOICE']);}
    }
    private function actor(int $id): User {$u=User::findOrFail($id);$u->setRelation('role',new Role(['name'=>$id===1?'company':'user']));return $u;}
    private function denied(callable $fn,int $status): void {try{$fn();self::fail('Expected denial');}catch(HttpException $e){self::assertSame($status,$e->getStatusCode(),$e->getMessage());}}
    private function invitation(object $order,string $email='exporter@example.test'): array {
        return app(SupplyChain::class)->act($order,$this->actor(2),['action'=>'invite','company'=>'Exporter Ltd','name'=>'Cargo contact','email'=>$email,'role'=>'exporter','required_documents'=>['ci','pl'],'request_note'=>'Supply export documentation.'])['contacts'][0];
    }
    public function test_buyer_invites_supplier_without_team_membership_or_price_access(): void {
        $this->book();$order=$this->order();$count=DB::table('company_user')->count();$contact=$this->invitation($order);$p=DB::table('ops_order_participants')->where('order_id',$order->id)->first();
        self::assertSame('supplier',$p->role);self::assertSame($count,DB::table('company_user')->count());self::assertFalse(app(OpsPortal::class)->buyer($order,$this->actor(3)));
        app(OpsPortal::class)->participantAction($order,$this->actor(3),'accept',$p->revision);
        self::assertTrue(app(SupplyChain::class)->supplier($order,$this->actor(3)));self::assertFalse(app(OpsPortal::class)->buyer($order,$this->actor(3)));
        $read=app(SupplyChain::class)->read($order,$this->actor(3));self::assertFalse($read['can_manage']);self::assertCount(1,$read['contacts']);self::assertArrayNotHasKey('agreed_total',$read);
        $this->denied(fn()=>app(\App\Services\Ops\CargoReview::class)->read($order,$this->actor(3)),403);
        $r=\Illuminate\Http\Request::create('/api/transport-orders/'.$order->id);$r->setUserResolver(fn()=>$this->actor(3));$route=$this->app['router']->getRoutes()->match($r);$r->setRouteResolver(fn()=>$route);$body=app(\App\Http\Controllers\Api\OpsPortalController::class)->show($r)->getData(true)['data'];self::assertTrue($body['supplier']);self::assertArrayNotHasKey('intake',$body);self::assertArrayNotHasKey('agreed_revenue',$body['order']);

        $this->denied(fn()=>app(SupplyChain::class)->read($order,$this->actor(4)),403);
    }
    public function test_supplier_can_supply_only_requested_owned_files_and_revocation_is_immediate(): void {
        $this->book();$order=$this->order();app(OpsPortal::class)->ensureDocumentOperation($order);$c=$this->invitation($order);$p=DB::table('ops_order_participants')->first();app(OpsPortal::class)->participantAction($order,$this->actor(3),'accept',$p->revision);
        app(OpsPortal::class)->attach($order,$this->actor(3),3,'ci');self::assertTrue(app(SupplyChain::class)->documentVisible($order,$this->actor(3),3));self::assertFalse(app(SupplyChain::class)->documentVisible($order,$this->actor(3),1));
        $this->denied(fn()=>app(OpsPortal::class)->attach($order,$this->actor(3),3,'pod'),403);$this->denied(fn()=>app(OpsPortal::class)->attach($order,$this->actor(3),4,'ci'),404);
        app(SupplyChain::class)->act($order,$this->actor(2),['action'=>'revoke','contact_id'=>$c['id'],'revision'=>$c['revision']]);self::assertFalse(app(SupplyChain::class)->documentVisible($order,$this->actor(3),3));$this->denied(fn()=>app(OpsPortal::class)->attach($order,$this->actor(3),3,'ci'),403);
    }
    public function test_new_external_contact_binds_only_after_email_verification(): void {
        $this->book();$order=$this->order();$this->invitation($order,'new@example.test');DB::table('users')->insert(['id'=>5,'name'=>'New supplier','email'=>'new@example.test']);$service=app(SupplyChain::class);
        $service->syncInvitations($this->actor(5));self::assertSame(0,DB::table('ops_order_participants')->count());DB::table('users')->where('id',5)->update(['email_verified_at'=>now()]);$service->syncInvitations($this->actor(5));self::assertSame(1,DB::table('ops_order_participants')->where('user_id',5)->where('role','supplier')->count());$service->syncInvitations($this->actor(5));self::assertSame(1,DB::table('ops_order_participants')->count());
    }
    public function test_supplier_cannot_change_other_contacts_and_cargo_readiness_is_not_dispatch(): void {
        $w=$this->book();$order=$this->order();$c=$this->invitation($order);$p=DB::table('ops_order_participants')->first();app(OpsPortal::class)->participantAction($order,$this->actor(3),'accept',$p->revision);
        $this->denied(fn()=>app(SupplyChain::class)->act($order,$this->actor(4),['action'=>'update','contact_id'=>$c['id'],'revision'=>1,'cargo_ready'=>true]),403);
        app(SupplyChain::class)->act($order,$this->actor(3),['action'=>'update','contact_id'=>$c['id'],'revision'=>1,'cargo_ready'=>true,'pickup_address'=>'Synthetic warehouse address']);self::assertSame('booked',$w->fresh()->status);self::assertSame(0,DB::table('ops_events')->where('event_type','loaded')->count());
        $this->denied(fn()=>app(SupplyChain::class)->act($order,$this->actor(3),['action'=>'update','contact_id'=>$c['id'],'revision'=>1]),409);
    }
    public function test_missing_documents_allow_preparation_but_block_physical_transport(): void {
        $this->book();$order=$this->order();app(OpsPortal::class)->ensureDocumentOperation($order);$items=app(OpsPortal::class)->rawItems($order);$items[]=['key'=>'booking_confirmation','status'=>'pending','plan_version'=>2,'responsible_user_id'=>1];\App\Models\ShipmentWorkspace::first()->update(['operational_checklist'=>$items]);
        $state=app(OperationalCompliance::class)->read($order);app(OperationalCompliance::class)->update(1,$order->id,1,['revision'=>$state['revision'],'fingerprint'=>$state['fingerprint'],'items'=>[['key'=>'booking_confirmation','status'=>'in_progress']]]);
        $this->ops->event(1,1,$order->id,['event_type'=>'dispatched','note'=>'Carrier and pickup planning']);$this->denied(fn()=>$this->ops->event(1,1,$order->id,['event_type'=>'loaded']),409);
    }
    private function bookingFixture(string $status='pending',bool $fail=false,bool $realCreator=false): void {
        Schema::table('loads',function(Blueprint $t){$t->string('status')->default('posted');$t->json('status_change')->nullable();foreach(['customer_user_id','company_id','assigned_driver_user_id'] as $k)$t->unsignedBigInteger($k)->nullable();foreach(['pre_delivery_status','booking_status','booking_reference'] as $k)$t->string($k)->nullable();$t->timestamps();});DB::table('loads')->where('id',3)->update(['customer_user_id'=>2]);
        Schema::create('shipments',function(Blueprint $t){$t->id();$t->unsignedBigInteger('load_id');});Schema::create('offers',function(Blueprint $t){$t->id();$t->unsignedBigInteger('load_id');$t->unsignedBigInteger('company_id')->nullable();$t->unsignedBigInteger('driver_user_id')->nullable();$t->string('status');$t->timestamps();});DB::table('offers')->insert(['id'=>99,'load_id'=>3,'company_id'=>1,'status'=>$status]);
        if($realCreator){
            Schema::table('offers',function(Blueprint $t){$t->unsignedBigInteger('created_by_user_id')->nullable();$t->decimal('amount',14,2)->default(3000);$t->string('currency')->default('EUR');});DB::table('offers')->where('id',99)->update(['created_by_user_id'=>1]);
            Schema::table('shipment_workspace',function(Blueprint $t){$t->unsignedBigInteger('shipment_id')->nullable();$t->unsignedBigInteger('conversation_id')->nullable();});
            Schema::create('conversations',function(Blueprint $t){$t->id();foreach(['company_id','load_id','created_by_user_id'] as $k)$t->unsignedBigInteger($k)->nullable();$t->string('channel');$t->string('subject');$t->timestamp('last_message_at')->nullable();$t->timestamps();$t->softDeletes();});
            Schema::create('conversation_user',function(Blueprint $t){$t->unsignedBigInteger('conversation_id');$t->unsignedBigInteger('user_id');$t->timestamps();});
            Schema::create('messages',function(Blueprint $t){$t->id();$t->unsignedBigInteger('conversation_id');$t->unsignedBigInteger('sender_user_id');$t->text('body');$t->timestamp('sent_at');$t->timestamps();});
            return;
        }
        $creator=\Mockery::mock(\App\Services\ShipmentWorkspaceCreator::class);$creator->shouldReceive('create')->once()->andReturnUsing(function()use($fail){if($fail)throw new \RuntimeException('Simulated workspace failure');$w=$this->book();$plan=app(\App\Services\Ops\OperationsPlan::class)->booking(\App\Models\Load::findOrFail(3),\App\Models\Offer::findOrFail(99),1);$w->update(['accepted_offer_id'=>99,'operational_checklist'=>$plan]);return $w;});$this->app->instance(\App\Services\ShipmentWorkspaceCreator::class,$creator);
    }
    public function test_acceptance_creates_booking_and_order_before_ci_pl_and_is_idempotent(): void {
        $this->bookingFixture();$service=app(\App\Services\OfferBooking::class);$this->denied(fn()=>$service->confirm($this->actor(3),99),403);[$offer,$w]=$service->confirm($this->actor(2),99);self::assertSame('accepted',$offer->status);self::assertSame('booked',DB::table('loads')->where('id',3)->value('status'));self::assertNotNull($this->order());self::assertSame(0,DB::table('ops_order_documents')->count());
        self::assertTrue(collect($w->fresh()->operational_checklist)->contains('key','documents_received'));self::assertFalse(app(OpsPortal::class)->documentState($this->order())['review_complete']);
        $this->denied(fn()=>$this->ops->event(1,1,$this->order()->id,['event_type'=>'loaded']),409);
        [, $again]=$service->confirm($this->actor(2),99);self::assertSame($w->id,$again->id);self::assertSame(1,DB::table('shipment_workspace')->count());self::assertSame(1,DB::table('ops_orders')->count());
    }
    public function test_legacy_accepted_offer_is_repaired_without_accepting_a_new_offer(): void {
        $this->bookingFixture('accepted');$service=app(\App\Services\OfferBooking::class);self::assertSame(1,$service->repairMine($this->actor(2)));self::assertSame(0,$service->repairMine($this->actor(2)));self::assertSame(1,DB::table('shipment_workspace')->count());
    }
    public function test_real_booking_creator_and_model_hook_commit_without_any_documents(): void {
        $this->bookingFixture(realCreator:true);
        [$offer,$workspace]=app(\App\Services\OfferBooking::class)->confirm($this->actor(2),99);
        self::assertSame('accepted',$offer->fresh()->status);self::assertSame('booked',\App\Models\Load::findOrFail(3)->status);
        self::assertSame(99,(int)$workspace->accepted_offer_id);self::assertSame(3000.0,(float)$workspace->agreed_amount);
        self::assertTrue(collect($workspace->fresh()->operational_checklist)->contains('key','documents_received'));
        self::assertSame($workspace->id,(int)$this->order()->workspace_id);self::assertSame(0,DB::table('ops_order_documents')->count());
        self::assertSame(1,DB::table('messages')->count());self::assertSame(2,DB::table('conversation_user')->count());
        [, $again]=app(\App\Services\OfferBooking::class)->confirm($this->actor(2),99);self::assertSame($workspace->id,$again->id);self::assertSame(1,DB::table('ops_orders')->count());
    }
    public function test_failed_workspace_creation_rolls_back_acceptance(): void {
        $this->bookingFixture('pending',true);try{app(\App\Services\OfferBooking::class)->confirm($this->actor(2),99);self::fail('Expected failure');}catch(\RuntimeException $e){self::assertSame('Simulated workspace failure',$e->getMessage());}
        self::assertSame('pending',DB::table('offers')->where('id',99)->value('status'));self::assertSame(0,DB::table('shipment_workspace')->count());self::assertSame(0,DB::table('ops_orders')->count());
    }
    public function test_supplier_acceptance_requires_verified_email(): void {
        $this->book();$order=$this->order();$this->invitation($order);$p=DB::table('ops_order_participants')->first();DB::table('users')->where('id',3)->update(['email_verified_at'=>null]);
        $this->denied(fn()=>app(OpsPortal::class)->participantAction($order,$this->actor(3),'accept',$p->revision),422);self::assertSame('invited',DB::table('ops_order_participants')->value('status'));
    }

}
