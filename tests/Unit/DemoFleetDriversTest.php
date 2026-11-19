<?php
namespace Tests\Unit;
use App\Services\{DemoFleetDrivers,CompanyTeamAccess};
use App\Models\{User,Driver};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema,Hash};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DemoFleetDriversTest extends VehicleRegistryTest {
 protected function setUp():void {
  parent::setUp();DB::table('companies')->where('id',1)->update(['name'=>DemoFleetDrivers::COMPANY]);
  Schema::table('users',function(Blueprint $t){$t->string('username')->nullable()->unique();$t->string('password')->nullable();$t->string('phone')->nullable();$t->string('language')->nullable();$t->string('country_code')->nullable();$t->boolean('is_active')->default(true);$t->timestamp('email_verified_at')->nullable();$t->text('bio')->nullable();$t->timestamps();});
  Schema::table('drivers',function(Blueprint $t){$t->string('name')->nullable();$t->string('email')->nullable();$t->string('phone')->nullable();$t->string('country_code')->nullable();$t->timestamp('profile_authorized_at')->nullable();$t->string('license_number')->nullable()->unique();$t->string('license_country_code')->nullable();$t->date('license_expires_at')->nullable();$t->string('availability_status')->nullable();$t->timestamps();});
 }
 private function credentials():array{return array_map(fn($i)=>['username'=>DemoFleetDrivers::identity($i)['username'],'password'=>'DemoTest-Only-Password-'.$i.'-2026'],range(1,10));}
 public function test_demo_batch_creates_ten_selectable_drivers_and_is_idempotent():void {
  $service=app(DemoFleetDrivers::class);$before=$service->inspect();self::assertSame(10,$before['to_create']);self::assertSame(0,$before['changes']);$credentials=$this->credentials();$result=$service->create($before['fingerprint'],$credentials);
  self::assertSame(10,$result['created']);self::assertCount(10,$result['profiles']);self::assertSame(0,$result['messages_sent']);
  foreach($result['profiles'] as $i=>$row){$user=User::find($row['user_id']);self::assertSame('driver',app(CompanyTeamAccess::class)->companyRole($user,1));self::assertTrue($row['active']);self::assertTrue(Hash::check($credentials[$i]['password'],$user->password));}
  $again=$service->create($before['fingerprint'],$credentials);self::assertSame(0,$again['created']);self::assertSame(10,$again['reused']);self::assertSame(0,DB::table('vehicles')->whereIn('assigned_driver_user_id',array_column($again['profiles'],'user_id'))->count());
 }
 public function test_demo_identifier_collision_is_not_overwritten():void {
  $identity=DemoFleetDrivers::identity(10);$id=DB::table('users')->insertGetId(['name'=>'Existing real account','username'=>$identity['username'],'email'=>'existing@example.test','role_id'=>9]);$count=User::count();
  try{app(DemoFleetDrivers::class)->inspect();self::fail('Collision accepted');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
  self::assertSame($count,User::count());self::assertSame('Existing real account',User::find($id)->name);
 }
 public function test_demo_retry_never_resets_a_password_or_changes_database_target():void {
  $service=app(DemoFleetDrivers::class);$inspect=$service->inspect();$credentials=$this->credentials();$result=$service->create($inspect['fingerprint'],$credentials);$credentials[0]['password']='Different-Password-For-Retry-2026';
  try{$service->create($inspect['fingerprint'],$credentials);self::fail('Password changed');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
  self::assertTrue(Hash::check('DemoTest-Only-Password-1-2026',User::find($result['profiles'][0]['user_id'])->password));
  try{$service->create(str_repeat('0',64),$this->credentials());self::fail('Wrong target accepted');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
 }
}
