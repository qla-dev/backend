<?php
namespace Tests\Unit;
use App\Services\DemoFleetVehicles;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
class DemoFleetVehiclesTest extends VehicleRegistryTest {
 protected function setUp():void {
  parent::setUp();DB::table('companies')->where('id',1)->update(['name'=>DemoFleetVehicles::COMPANY]);
  Schema::table('fleet_access',function(Blueprint $t){$t->boolean('can_dispatch')->default(false);$t->boolean('can_edit')->default(false);$t->unsignedBigInteger('granted_by_user_id')->nullable();$t->timestamps();});
  Schema::create('vehicle_locations',function(Blueprint $t){$t->id();$t->unsignedBigInteger('vehicle_id');$t->timestamp('recorded_at');});
 }
 public function test_demo_vehicles_create_ten_distinct_bodies_without_assuming_legal_specs():void {
  $s=app(DemoFleetVehicles::class);$before=$s->inspect();self::assertSame(10,$before['to_create']);self::assertSame(0,$before['changes']);$result=$s->create($before['fingerprint']);self::assertSame(10,$result['created']);self::assertCount(10,$result['vehicles']);
  foreach($result['vehicles'] as $row){$v=Vehicle::findOrFail($row['id']);self::assertSame(1,(int)$v->company_id);self::assertTrue($v->features['demo_record']);self::assertSame('synthetic_test_data',$v->features['specification_source']);self::assertNull($v->assigned_driver_user_id);self::assertNull($v->capacity_kg);self::assertArrayNotHasKey('legal_category',$v->features);}
  self::assertGreaterThanOrEqual(6,count(array_unique(array_column($result['vehicles'],'body_type_code'))));$again=$s->create($before['fingerprint']);self::assertSame(0,$again['created']);self::assertSame(10,$again['reused']);self::assertSame(0,$s->inspect()['to_create']);
 }
 public function test_demo_vehicle_collision_never_overwrites_an_existing_vehicle():void {
  Vehicle::find(1)->update(['registration_number'=>'FB-DEMO-010']);$count=Vehicle::count();try{app(DemoFleetVehicles::class)->inspect();self::fail('Collision accepted');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}self::assertSame($count,Vehicle::count());self::assertArrayNotHasKey('demo_record',Vehicle::find(1)->features??[]);
 }
 public function test_demo_vehicle_target_mismatch_cannot_write():void {
  $count=Vehicle::count();try{app(DemoFleetVehicles::class)->create(str_repeat('0',64));self::fail('Wrong target accepted');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}self::assertSame($count,Vehicle::count());
 }
 public function test_demo_vehicle_failure_rolls_back_the_whole_batch():void {
  $s=app(DemoFleetVehicles::class);$before=$s->inspect();$count=Vehicle::count();
  Vehicle::creating(function($v){if($v->registration_number==='FB-DEMO-006')throw new \RuntimeException('Simulated write failure');});
  try{try{$s->create($before['fingerprint']);self::fail('Failure ignored');}catch(\RuntimeException $e){self::assertSame('Simulated write failure',$e->getMessage());}}finally{Vehicle::flushEventListeners();}
  self::assertSame($count,Vehicle::count());self::assertSame(10,$s->inspect()['to_create']);
 }
}
