<?php

namespace Tests\Unit;

use App\Models\Vehicle;
use App\Models\User;
use App\Services\VehicleRegistry;
use App\Services\FleetOperations;
use App\Services\VehicleCouplings;
use App\Http\Controllers\Api\FleetCouplingController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VehicleRegistryTest extends FleetDepotAccessTest
{
    protected function setUp():void {
        parent::setUp();
        Schema::table('vehicles',function(Blueprint $t){$t->string('transport_type')->nullable();$t->decimal('capacity_kg',15,2)->nullable();$t->decimal('capacity_m3',15,2)->nullable();});
        (require database_path('migrations/2026_11_19_000001_create_fleet_vehicle_couplings.php'))->up();
    }
    private function request(int $user,array $data=[]):Request {$r=Request::create('/api/fleet','POST',$data);$r->setUserResolver(fn()=>User::findOrFail($user));return $r;}
    private function assets():void {
        Vehicle::find(1)->update(['features'=>['resource_type'=>'tractor_unit','body_type_code'=>'none']]);
        Vehicle::create(['id'=>4,'company_id'=>1,'depot_id'=>10,'registration_number'=>'TRAILER-4','status'=>'depot','features'=>['resource_type'=>'semi_trailer','body_type_code'=>'box']]);
    }
    public function test_legal_mass_boundaries():void {
        foreach([[3500,'N1'],[3500.1,'N2'],[12000,'N2'],[12000.1,'N3']] as [$mass,$category])self::assertSame($category,VehicleRegistry::category($mass));
        foreach([[750,'O1'],[751,'O2'],[3500,'O2'],[3501,'O3'],[10000,'O3'],[10001,'O4']] as [$mass,$category])self::assertSame($category,VehicleRegistry::category($mass,true));
    }
    public function test_payload_and_gross_mass_are_not_interchangeable():void {
        $data=app(VehicleRegistry::class)->validate(['capacity_kg'=>1200,'features'=>['resource_type'=>'cargo_van','body_type_code'=>'panel_van','legal_category'=>'N1','gross_weight_kg'=>3500,'unladen_weight_kg'=>2200]]);
        self::assertSame(1200,$data['capacity_kg']);self::assertSame(3500,$data['features']['gross_weight_kg']);
        $this->expectException(ValidationException::class);
        app(VehicleRegistry::class)->validate(['capacity_kg'=>1500,'features'=>$data['features']]);
    }
    public function test_ranges_are_rejected_server_side():void {
        $this->expectException(ValidationException::class);app(VehicleRegistry::class)->validate(['capacity_kg'=>'3 - 40 t']);
    }
    public function test_tractor_has_no_payload_or_box():void {
        $this->expectException(ValidationException::class);app(VehicleRegistry::class)->validate(['capacity_kg'=>20000,'features'=>['resource_type'=>'tractor_unit','body_type_code'=>'box']]);
    }
    public function test_external_dimensions_cannot_validate_internal_loading_space():void {
        $this->expectException(ValidationException::class);app(VehicleRegistry::class)->validate(['features'=>['resource_type'=>'rigid_truck','body_type_code'=>'box','width_m'=>2.4,'cargo_width_m'=>2.6]]);
    }
    public function test_legacy_suspicious_values_are_reported_without_rewriting():void {
        $v=Vehicle::find(1);$v->update(['capacity_kg'=>340000,'capacity_m3'=>201003]);
        self::assertContains('capacity_review',VehicleRegistry::warnings($v));self::assertSame(340000.0,(float)$v->fresh()->capacity_kg);
        $data=app(VehicleRegistry::class)->validate(['features'=>['next_service'=>'Next month']],$v);
        self::assertSame('Next month',$data['features']['next_service']);self::assertArrayNotHasKey('capacity_kg',$data);
    }
    public function test_van_licence_guidance_depends_on_operation_not_name():void {
        $f=['resource_type'=>'cargo_van','operation_country'=>'DE','operation_scope'=>'international','operation_kind'=>'hire_reward','gross_weight_kg'=>3500];
        self::assertContains('community_licence_copy',VehicleRegistry::guidance($f)['documents']);
        $f['operation_scope']='domestic';self::assertNotContains('community_licence_copy',VehicleRegistry::guidance($f)['documents']);
        self::assertSame('pending_review',VehicleRegistry::guidance($f)['status']);
    }
    public function test_coupling_and_detachment_keep_history_and_prevent_double_booking():void {
        $this->assets();$controller=app(FleetCouplingController::class);
        $first=$controller->attach($this->request(1,['trailer_id'=>4,'position'=>1,'reason'=>'Start shift']),1,1)->getData(true)['data']['id'];
        self::assertSame(1,DB::table('fleet_vehicle_couplings')->whereNull('ended_at')->count());
        try{$controller->attach($this->request(1,['trailer_id'=>4,'position'=>2,'reason'=>'Duplicate']),1,1);self::fail('Duplicate coupling accepted');}catch(HttpExceptionInterface $e){self::assertSame(422,$e->getStatusCode());}
        $controller->detach($this->request(1,['reason'=>'End shift']),1,1,$first);
        self::assertSame(1,DB::table('fleet_vehicle_couplings')->count());self::assertSame(0,DB::table('fleet_vehicle_couplings')->whereNull('ended_at')->count());
        self::assertSame('End shift',DB::table('fleet_vehicle_couplings')->value('detach_reason'));
        $controller->attach($this->request(1,['trailer_id'=>4,'position'=>1,'reason'=>'Next shift']),1,1);self::assertSame(2,DB::table('fleet_vehicle_couplings')->count());
    }
    public function test_coupling_requires_both_depot_scopes():void {
        $this->assets();Vehicle::find(4)->update(['depot_id'=>11]);
        try{app(FleetCouplingController::class)->attach($this->request(4,['trailer_id'=>4,'position'=>1,'reason'=>'Attempt']),1,1);self::fail('Foreign depot accepted');}catch(HttpExceptionInterface $e){self::assertSame(403,$e->getStatusCode());}
        self::assertSame(0,DB::table('fleet_vehicle_couplings')->count());
    }
    public function test_classification_and_archival_require_detachment():void {
        $this->assets();app(FleetCouplingController::class)->attach($this->request(1,['trailer_id'=>4,'position'=>1,'reason'=>'Coupled']),1,1);
        try{VehicleCouplings::assertDetached(Vehicle::find(4));self::fail('Coupled trailer modified');}catch(HttpExceptionInterface $e){self::assertSame(422,$e->getStatusCode());}
    }
    public function test_trailer_cannot_be_dispatched_as_a_truck():void {
        $this->assets();$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(FleetOperations::class)->assertDispatchable(4);
    }
    public function test_tractor_without_a_trailer_cannot_be_dispatched():void {
        $this->assets();$this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);app(FleetOperations::class)->assertDispatchable(1);
    }
    public function test_vehicle_api_rejects_ranges_before_writing():void {
        $before=Vehicle::count();
        try{app(\App\Http\Controllers\Api\VehicleController::class)->store($this->request(1,['registration_number'=>'BAD-RANGE','vehicle_type'=>'rigid_truck','capacity_kg'=>'3 - 40 t']));self::fail('Range accepted');}
        catch(ValidationException $e){self::assertArrayHasKey('capacity_kg',$e->errors());}
        self::assertSame($before,Vehicle::count());
    }
    public function test_vehicle_api_creates_an_independent_trailer():void {
        Schema::create('vehicle_locations',function(Blueprint $t){$t->id();$t->unsignedBigInteger('vehicle_id');$t->timestamp('recorded_at');});
        Schema::table('fleet_access',function(Blueprint $t){$t->boolean('can_dispatch')->default(false);$t->boolean('can_edit')->default(false);$t->unsignedBigInteger('granted_by_user_id')->nullable();$t->timestamps();});
        app(\App\Http\Controllers\Api\VehicleController::class)->store($this->request(1,['registration_number'=>'NEW-TRAILER','vehicle_type'=>'semi_trailer','transport_type'=>'road','company_id'=>1,'depot_id'=>10,'home_depot_id'=>10,'capacity_kg'=>24000,'features'=>['resource_type'=>'semi_trailer','body_type_code'=>'curtainsider','legal_category'=>'O4','gross_weight_kg'=>36000]]));
        $v=Vehicle::where('registration_number','NEW-TRAILER')->firstOrFail();
        self::assertSame('semi_trailer',$v->features['resource_type']);self::assertNull($v->assigned_driver_user_id);self::assertSame(24000.0,(float)$v->capacity_kg);
    }
    public function test_registry_audit_is_read_only():void {
        Vehicle::find(1)->update(['capacity_kg'=>340000,'capacity_m3'=>201003]);$before=Vehicle::count();
        self::assertSame(0,\Illuminate\Support\Facades\Artisan::call('fleet:audit-registry',['--summary'=>true]));
        $out=json_decode(\Illuminate\Support\Facades\Artisan::output(),true);
        self::assertGreaterThanOrEqual(1,$out['warnings']['capacity_review']);self::assertSame(0,$out['changes']);
        self::assertSame($before,Vehicle::count());self::assertSame(340000.0,(float)Vehicle::find(1)->capacity_kg);
    }
}
