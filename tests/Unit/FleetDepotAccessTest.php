<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FleetDepotController;
use App\Http\Controllers\Api\FleetOperationsController;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetDepotAccess;
use App\Services\FleetOperations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FleetDepotAccessTest extends FleetOperationsTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::table('vehicles',function(Blueprint $t){$t->unsignedBigInteger('owner_user_id')->nullable();$t->string('vin')->nullable();$t->string('make')->nullable();$t->string('model')->nullable();$t->json('features')->nullable();});
        DB::table('vehicles')->insert(['id'=>3,'company_id'=>1,'depot_id'=>11,'registration_number'=>'LEI-003','status'=>'sold','ownership_type'=>'owned']);
        $this->grant(4,'dispatcher',[10]);
        Schema::create('fleet_access',function(Blueprint $t){$t->id();$t->unsignedBigInteger('vehicle_id');$t->unsignedBigInteger('user_id');$t->boolean('can_view')->default(false);});
        Schema::table('documents',fn(Blueprint $t)=>$t->unsignedBigInteger('vehicle_id')->nullable());
    }
    private function requestFor(int $user,array $body=[]): Request {$r=Request::create('/fleet','POST',$body);$r->setUserResolver(fn()=>User::findOrFail($user));return $r;}
    private function grant(int $user,string $role,array $depots=[],array $vehicles=[]):void {
        DB::table('fleet_member_scopes')->updateOrInsert(['company_id'=>1,'user_id'=>$user],['fleet_role'=>$role,'all_depots'=>false,'depot_ids'=>json_encode($depots),'vehicle_ids'=>json_encode($vehicles)]);
    }
    private function denied(callable $fn):void {try{$fn();self::fail('Access must be rejected.');}catch(HttpExceptionInterface $e){self::assertContains($e->getStatusCode(),[403,404]);}}

    public function test_depot_scope_limits_context_and_archive_counts():void {
        $c=app(FleetOperationsController::class)->context($this->requestFor(4),1)->getData(true)['data'];
        self::assertSame([1],array_column($c['vehicles'],'id'));self::assertSame([10],array_column($c['depot_records'],'id'));
        $c=app(FleetDepotController::class)->overview($this->requestFor(4,['include_archived'=>1]),1)->getData(true)['data'];
        self::assertSame(1,$c['total']);self::assertSame(['in_use'=>1],$c['counts']);
    }
    public function test_company_admin_counts_archived_and_unassigned_assets():void {
        $c=app(FleetDepotController::class)->overview($this->requestFor(1),1)->getData(true)['data'];
        self::assertSame(2,$c['total']);self::assertSame(1,$c['counts']['sold']);self::assertSame(1,$c['vehicles']['total']);
    }
    public function test_overview_query_count_does_not_grow_per_vehicle():void {
        DB::enableQueryLog();
        app(FleetDepotController::class)->overview($this->requestFor(1),1);
        $baseline=count(DB::getQueryLog());
        for($id=10;$id<34;$id++)DB::table('vehicles')->insert(['id'=>$id,'company_id'=>1,'depot_id'=>10,'registration_number'=>'TEST-'.$id,'status'=>'depot','ownership_type'=>'owned']);
        DB::flushQueryLog();
        $data=app(FleetDepotController::class)->overview($this->requestFor(1),1)->getData(true)['data'];
        $queries=count(DB::getQueryLog());DB::disableQueryLog();
        self::assertCount(25,$data['vehicles']['data']);
        self::assertLessThanOrEqual($baseline+1,$queries,'Additional vehicles must not add individual mileage/tour queries.');
    }
    public function test_overview_and_detail_only_include_current_company_tours():void {
        Schema::create('vehicle_locations',function(Blueprint $t){$t->id();$t->unsignedBigInteger('vehicle_id');$t->timestamp('recorded_at');$t->decimal('latitude')->nullable();$t->decimal('longitude')->nullable();});
        DB::table('fleet_tours')->insert(['company_id'=>2,'service_date'=>now()->toDateString(),'depot_id'=>12,'depot_name'=>'Other','partner'=>'Foreign','tour_code'=>'SECRET','vehicle_id'=>1,'status'=>'open','created_by'=>1]);
        DB::table('fleet_tours')->insert(['company_id'=>1,'service_date'=>now()->toDateString(),'depot_id'=>10,'depot_name'=>'Berlin','partner'=>'Own','tour_code'=>'OWN-1','vehicle_id'=>1,'status'=>'open','created_by'=>1]);
        $overview=app(FleetDepotController::class)->overview($this->requestFor(1),1)->getData(true)['data'];
        self::assertSame(['OWN-1'],array_column($overview['vehicles']['data'][0]['active_tours'],'tour_code'));
        $detail=app(FleetDepotController::class)->detail($this->requestFor(1),1,1)->getData(true)['data'];
        self::assertSame(['OWN-1'],array_column($detail['vehicle']['active_tours'],'tour_code'));
        self::assertSame(0,$detail['pending_handovers']);self::assertSame(0,$detail['open_damages']);
    }
    public function test_database_busy_is_safe_even_before_authentication():void {
        $pdo=new \PDOException('Secret SQL and token must not reach the client');
        $pdo->errorInfo=['HY000',1040,'Too many connections'];
        $exception=new \Illuminate\Database\QueryException('mysql','select * from personal_access_tokens where id = 513',[],$pdo);
        DB::enableQueryLog();DB::flushQueryLog();
        $response=app(\Illuminate\Contracts\Debug\ExceptionHandler::class)->render(Request::create('/api/fleet-operations/1/overview','GET',[],[],[],['HTTP_ACCEPT'=>'application/json']),$exception);
        self::assertSame(503,$response->getStatusCode());
        self::assertSame('5',$response->headers->get('Retry-After'));
        self::assertSame('DATABASE_BUSY',$response->getData(true)['code']);
        self::assertStringNotContainsString('personal_access_tokens',$response->getContent());
        self::assertStringNotContainsString('Secret',$response->getContent());
        self::assertCount(0,DB::getQueryLog());DB::disableQueryLog();
        $pdo->errorInfo=['HY000',1045,'Access denied'];
        self::assertFalse(\App\Support\DatabaseBusy::matches($pdo));
    }
    public function test_depot_filter_cannot_expand_member_scope():void {
        $this->denied(fn()=>app(FleetDepotController::class)->overview($this->requestFor(4,['depot_id'=>11]),1));
    }
    public function test_direct_foreign_handover_and_review_are_denied():void {
        $h=DB::transaction(fn()=>app(FleetOperations::class)->handover(Vehicle::find(1),1,['destination'=>'depot','depot_id'=>11,'mileage_km'=>100,'fuel_level_percent'=>50]));
        // Historical source depot may still review its own outgoing record.
        self::assertSame($h,app(FleetOperationsController::class)->handoverDetail($this->requestFor(4),1,$h)->getData(true)['data']['handover']['id']);
        $this->grant(4,'dispatcher',[]);
        $this->denied(fn()=>app(FleetOperationsController::class)->handoverDetail($this->requestFor(4),1,$h));
        $this->denied(fn()=>app(FleetOperationsController::class)->review($this->requestFor(4,['decision'=>'clear']),1,$h));
    }
    public function test_transfer_requires_access_to_source_and_target():void {
        $this->denied(fn()=>DB::transaction(fn()=>app(FleetOperations::class)->handover(Vehicle::find(1),4,['destination'=>'depot','depot_id'=>11,'mileage_km'=>100,'fuel_level_percent'=>50])));
        self::assertSame(10,(int)Vehicle::find(1)->depot_id);
    }
    public function test_damage_retains_original_depot_after_transfer():void {
        $id=app(FleetOperations::class)->damage(1,1,1,null,['description'=>'Original damage']);
        DB::transaction(fn()=>app(FleetOperations::class)->handover(Vehicle::find(1),1,['destination'=>'depot','depot_id'=>11,'mileage_km'=>100,'fuel_level_percent'=>50]));
        self::assertSame(10,(int)DB::table('fleet_damages')->find($id)->depot_id);
        self::assertSame($id,app(FleetOperationsController::class)->damageDetail($this->requestFor(4),1,$id)->getData(true)['data']['damage']['id']);
        $this->grant(4,'dispatcher',[11]);
        $this->denied(fn()=>app(FleetOperationsController::class)->damageDetail($this->requestFor(4),1,$id));
    }
    public function test_notification_scope_uses_damage_not_current_vehicle():void {
        app(FleetOperations::class)->damage(1,1,1,null,['description'=>'Berlin']);
        app(FleetOperations::class)->damage(1,1,3,null,['description'=>'Leipzig']);
        $rows=app(FleetOperationsController::class)->notifications($this->requestFor(4))->getData(true)['data'];
        self::assertSame(['Berlin'],array_column($rows,'description'));
    }
    public function test_scoped_member_cannot_grant_permissions():void {
        $this->denied(fn()=>app(FleetDepotController::class)->grant($this->requestFor(4,['fleet_role'=>'fleet_manager','all_depots'=>true,'depot_ids'=>[]]),1,4));
    }
    public function test_foreign_depot_cannot_be_granted_by_company_admin():void {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(FleetDepotController::class)->grant($this->requestFor(1,['fleet_role'=>'dispatcher','all_depots'=>false,'depot_ids'=>[12]]),1,4);
    }
    public function test_revoked_membership_invalidates_saved_fleet_grant():void {
        DB::table('company_user')->where('company_id',1)->where('user_id',4)->update(['status'=>'removed']);
        $this->denied(fn()=>app(FleetOperationsController::class)->context($this->requestFor(4),1));
    }
    public function test_new_member_has_no_implicit_fleet_grant():void {
        DB::table('fleet_member_scopes')->where('company_id',1)->where('user_id',4)->delete();
        $this->denied(fn()=>app(FleetOperationsController::class)->context($this->requestFor(4),1));
    }
    public function test_workshop_only_reads_explicit_vehicles_and_cannot_dispatch():void {
        $this->grant(4,'workshop',[],[1]);$a=app(FleetDepotAccess::class);
        $a->vehicle(User::find(4),Vehicle::find(1));
        $this->denied(fn()=>$a->vehicle(User::find(4),Vehicle::find(3)));
        $this->denied(fn()=>$a->authorize(User::find(4),1,'dispatch'));
        $this->denied(fn()=>app(FleetOperationsController::class)->damages($this->requestFor(4),1));
    }
    public function test_driver_may_return_vehicle_to_its_current_depot_only():void {
        self::assertSame(10,(int)app(FleetDepotAccess::class)->depot(User::find(6),1,10)->id);
        $this->denied(fn()=>app(FleetDepotAccess::class)->depot(User::find(6),1,11));
    }
    public function test_import_identity_survives_depot_rename():void {
        $p=['service_date'=>now()->toDateString(),'depot_id'=>10,'partner'=>'P','rows'=>[['tour_code'=>'001']],'confirm'=>true];
        $c=app(FleetOperationsController::class);$c->importTours($this->requestFor(1,$p),1);
        DB::table('fleet_depots')->where('id',10)->update(['name'=>'Berlin renamed']);
        $c->importTours($this->requestFor(1,$p),1);self::assertSame(1,DB::table('fleet_tours')->count());
        self::assertSame('Berlin',DB::table('fleet_tours')->value('depot_name'));
    }
    public function test_inactive_depot_remains_readable_but_cannot_receive_vehicle():void {
        DB::table('fleet_depots')->where('id',10)->update(['active'=>false]);
        self::assertSame(10,(int)app(FleetDepotAccess::class)->depot(User::find(4),1,10,false)->id);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(FleetDepotAccess::class)->depot(User::find(4),1,10);
    }

    public function test_legacy_document_cannot_be_moved_out_of_an_unauthorised_depot():void {
        $id=DB::table('documents')->insertGetId(['uploaded_by_user_id'=>1,'vehicle_id'=>3,'name'=>'private.pdf','type'=>'SERVICE','path'=>'private.pdf']);
        $r=Request::create('/api/documents/'.$id,'PATCH',['vehicle_id'=>1]);$r->setUserResolver(fn()=>User::find(4));
        $this->denied(fn()=>app(\App\Http\Middleware\EnsureFleetResourceScope::class)->handle($r,fn()=>response()->json(['leak'=>true])));
    }
    public function test_workshop_can_record_service_but_not_clear_dispatch_block():void {
        $this->grant(4,'workshop',[],[1]);
        DB::table('vehicles')->where('id',1)->update(['status'=>'maintenance','assigned_driver_user_id'=>null,'dispatch_blocked'=>true]);
        app(FleetDepotController::class)->service($this->requestFor(4,['next_service'=>'2026-12-15','reason'=>'Oil service']),1,1);
        self::assertSame('2026-12-15',Vehicle::find(1)->features['next_service']);
        app(FleetOperationsController::class)->vehicle($this->requestFor(4,['status'=>'depot','dispatch_blocked'=>true,'reason'=>'Repair completed']),1,1);
        self::assertSame('depot',Vehicle::find(1)->status);
        $this->denied(fn()=>app(FleetOperationsController::class)->vehicle($this->requestFor(4,['status'=>'depot','dispatch_blocked'=>false,'reason'=>'Release']),1,1));
    }
    public function test_driver_depot_grant_does_not_promote_driver_to_manager():void {
        app(FleetDepotController::class)->grant($this->requestFor(1,['fleet_role'=>'driver','all_depots'=>false,'depot_ids'=>[10]]),1,7);
        self::assertSame('driver',app(FleetDepotAccess::class)->scope(User::find(7),1)['role']);
        self::assertContains(7,app(FleetDepotAccess::class)->driverIds(User::find(4),1));
        $this->denied(fn()=>app(FleetDepotAccess::class)->authorize(User::find(7),1,'review'));
    }
    public function test_driver_list_is_limited_to_visible_vehicle_or_explicit_depot_grants():void {
        $c=app(FleetOperationsController::class)->context($this->requestFor(4),1)->getData(true)['data'];
        self::assertSame([6],array_column($c['drivers'],'id'));
    }
    public function test_read_only_release_preflight_does_not_change_rows_or_history():void {
        Schema::create('migrations',function(Blueprint $t){$t->id();$t->string('migration');$t->integer('batch');});
        $names=['2026_11_12_000001_create_fleet_operations','2026_11_17_000001_add_fleet_depots_and_access'];
        if(Schema::hasTable('fleet_vehicle_couplings'))$names[]='2026_11_19_000001_create_fleet_vehicle_couplings';
        if(!Schema::hasTable('crm_documents'))Schema::create('crm_documents',fn(Blueprint $t)=>$t->id());
        if(Schema::hasTable('fleet_handover_requests'))$names[]='2026_11_19_000002_create_freightbook_workflow_records';
        if(Schema::hasTable('vehicle_drafts'))$names[]='2026_11_20_000001_create_vehicle_drafts';
        if(Schema::hasTable('form_drafts'))$names[]='2026_11_20_000002_create_form_drafts';
        foreach($names as $name)DB::table('migrations')->insert(['migration'=>$name,'batch'=>1]);
        $before=DB::table('fleet_depots')->count();
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('freightbook:prepare-fleet-release',['--inspect'=>true]));
        self::assertSame($before,DB::table('fleet_depots')->count());self::assertSame(count($names),DB::table('migrations')->count());
    }
    public function test_draft_release_applies_only_approved_migrations_and_is_repeatable():void {
        $this->test_read_only_release_preflight_does_not_change_rows_or_history();
        $vehicles=DB::table('vehicles')->count();$users=DB::table('users')->count();
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('freightbook:prepare-fleet-release'));
        self::assertTrue(Schema::hasTable('vehicle_drafts'));self::assertTrue(Schema::hasTable('form_drafts'));
        self::assertTrue(Schema::hasColumns('form_drafts',['payload','revision','active_scope','access_hash']));
        self::assertSame(6,DB::table('migrations')->count());
        self::assertSame($vehicles,DB::table('vehicles')->count());self::assertSame($users,DB::table('users')->count());
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('freightbook:prepare-fleet-release'));
        self::assertSame(6,DB::table('migrations')->count());
    }
    public function test_draft_release_refuses_unregistered_draft_tables():void {
        $this->test_read_only_release_preflight_does_not_change_rows_or_history();
        if(!Schema::hasTable('form_drafts'))(require database_path('migrations/2026_11_20_000002_create_form_drafts.php'))->up();
        DB::table('migrations')->where('migration','2026_11_20_000002_create_form_drafts')->delete();
        $before=DB::table('migrations')->count();
        self::assertSame(1,\Illuminate\Support\Facades\Artisan::call('freightbook:prepare-fleet-release'));
        self::assertStringContainsString('Partial/unregistered draft schema',\Illuminate\Support\Facades\Artisan::output());
        self::assertSame($before,DB::table('migrations')->count());
    }
}
