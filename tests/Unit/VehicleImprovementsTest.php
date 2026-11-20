<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\VehicleDraftController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\DocumentController;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Document;
use App\Services\VehicleRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VehicleImprovementsTest extends VehicleRegistryTest
{
    protected function setUp():void
    {
        parent::setUp();
        (require database_path('migrations/2026_11_20_000001_create_vehicle_drafts.php'))->up();
        Schema::table('vehicles',function(Blueprint $t){$t->string('vehicle_type')->nullable();$t->integer('year')->nullable();});
        Schema::table('documents',function(Blueprint $t){$t->unsignedBigInteger('load_draft_id')->nullable();$t->unsignedBigInteger('user_id')->nullable();$t->text('comment')->nullable();$t->date('expires_at')->nullable();});
        // Do not clear another verification process's fake local disk.
        Storage::set('local',Storage::fake('vehicle-improvements-'.getmypid()));
    }

    private function actor(int $user,array $body=[],string $method='POST',array $files=[]):Request
    {
        $r=Request::create('/api/vehicles',$method,$body,[],$files);$r->setUserResolver(fn()=>User::findOrFail($user));return $r;
    }

    public static function truck():array
    {
        // Transcribed from the supplied registration certificate, not generic model specifications.
        // Year of manufacture, purchase ownership and service dates are not established by it.
        return ['company_id'=>1,'depot_id'=>10,'home_depot_id'=>10,'registration_number'=>'B CY 1311','vin'=>'W1T96340810915165','make'=>'Mercedes-Benz','model'=>'Actros','transport_type'=>'road','vehicle_type'=>'tractor_unit','ownership_type'=>'other','status'=>'depot','features'=>['resource_type'=>'tractor_unit','body_type_code'=>'none','legal_category'=>'N3','gross_weight_kg'=>18000,'unladen_weight_kg'=>9481,'gross_combination_weight_kg'=>44000,'length_m'=>6.253,'width_m'=>2.5,'height_m'=>3.914,'fuel_type'=>'diesel','operation_country'=>'DE','first_registration'=>'2026-08-31','mass_conditions'=>'44,000 kg combination subject to certificate field 22 conditions; not an unconditional dispatch allowance.']];
    }

    public function test_improvement_drafts_are_private_unique_and_revision_checked():void
    {
        $c=app(VehicleDraftController::class);$id=(string)\Illuminate\Support\Str::uuid();
        $body=['company_id'=>1,'form'=>['identity'=>['vin'=>self::truck()['vin']],'masses'=>['gross_weight_kg'=>'18','unladen_weight_kg'=>'9.481','gross_combination_weight_kg'=>'44'],'step'=>1],'revision'=>0];
        $c->save($this->actor(1,$body),$id);
        self::assertSame($body['form'],$c->show($this->actor(1,['company_id'=>1]))->getData(true)['data']['form']);
        self::assertNull($c->show($this->actor(3,['company_id'=>1]))->getData(true)['data']);
        self::assertSame(3,Vehicle::count(),'A draft is not an operational vehicle.');
        foreach([[$id,$body],[(string)\Illuminate\Support\Str::uuid(),$body]] as [$other,$data]){
            try{$c->save($this->actor(1,$data),$other);self::fail('Duplicate or stale draft accepted');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
        }
        $c->save($this->actor(1,array_replace($body,['revision'=>1,'form'=>['complete'=>'all fields retained']])),$id);
        self::assertSame(2,DB::table('vehicle_drafts')->where('id',$id)->value('revision'));
        $c->destroy($this->actor(1,['company_id'=>1]),$id);
        self::assertNull($c->show($this->actor(1,['company_id'=>1]))->getData(true)['data']);
        try{$c->save($this->actor(1,array_replace($body,['revision'=>2])),$id);self::fail('Deleted draft resurrected');}catch(HttpExceptionInterface $e){self::assertSame(409,$e->getStatusCode());}
    }

    public function test_improvement_supplied_actros_registers_once_without_documents_then_uploads():void
    {
        $this->prepareRegistration();
        $id=(string)\Illuminate\Support\Str::uuid();$drafts=app(VehicleDraftController::class);
        $drafts->save($this->actor(1,['company_id'=>1,'revision'=>0,'form'=>['identity'=>['vin'=>self::truck()['vin']]]]),$id);
        $controller=app(VehicleController::class);$body=self::truck()+['draft_id'=>$id];
        $first=$controller->store($this->actor(1,$body))->getData(true)['data'];
        $second=$controller->store($this->actor(1,$body))->getData(true)['data'];
        self::assertSame($first['id'],$second['id']);self::assertSame(1,Vehicle::where('vin',self::truck()['vin'])->count());
        self::assertSame(18000,$first['features']['gross_weight_kg']);self::assertSame(9481,$first['features']['unladen_weight_kg']);self::assertSame(44000,$first['features']['gross_combination_weight_kg']);
        self::assertSame([],$first['documents']);self::assertNull($drafts->show($this->actor(1,['company_id'=>1]))->getData(true)['data']);
        $recovered=$drafts->show($this->actor(1,['company_id'=>1,'recovery_id'=>$id]))->getData(true)['data'];self::assertSame($first['id'],$recovered['form']['savedVehicle']['id']);
        $documents=app(DocumentController::class);
        $upload=$documents->upload($this->actor(1,['vehicle_id'=>$first['id'],'type'=>'VEHICLE_REGISTRATION'],'POST',['file'=>UploadedFile::fake()->createWithContent('registration.pdf',"%PDF-1.4\n%%EOF\n")]))->getData(true)['data'];
        self::assertSame($first['id'],$upload['vehicle_id']);self::assertSame('VEHICLE_REGISTRATION',$upload['type']);
        Storage::disk('local')->assertExists('documents/'.$upload['path']);
        $replace=$documents->replace($this->actor(1,[],'POST',['file'=>UploadedFile::fake()->createWithContent('replacement.pdf',"%PDF-1.4\n%%EOF\n")]),$upload['id'])->getData(true)['data'];
        self::assertSame($first['id'],$replace['vehicle_id']);self::assertSame('Replaces document #'.$upload['id'],$replace['reference']);self::assertNotNull(Document::find($upload['id']));
        $download=$documents->download($this->actor(1,[],'GET'),$replace['id']);self::assertSame(200,$download->getStatusCode());
        $documents->destroy($this->actor(1),$replace['id']);self::assertNull(Document::find($replace['id']));self::assertNotNull(Vehicle::find($first['id']));
    }

    public function prepareRegistration():void
    {
        Schema::create('vehicle_locations',function(Blueprint $t){$t->id();$t->unsignedBigInteger('vehicle_id');$t->timestamp('recorded_at');});
        Schema::table('fleet_access',function(Blueprint $t){$t->boolean('can_dispatch')->default(false);$t->boolean('can_edit')->default(false);$t->unsignedBigInteger('granted_by_user_id')->nullable();$t->timestamps();});
    }

    public function test_improvement_optional_dates_fuels_and_all_iso_countries():void
    {
        $this->prepareRegistration();
        $registry=app(VehicleRegistry::class);$base=self::truck();
        $vehicle=Vehicle::findOrFail(1);$vehicle->update(['transport_type'=>'road','features'=>$base['features'],'assigned_driver_user_id'=>null]);
        $controller=app(VehicleController::class);
        self::assertCount(249,array_unique(explode(' ',VehicleRegistry::COUNTRIES)));
        foreach(VehicleRegistry::FUEL_TYPES as $fuel){$data=$base;$data['features']['fuel_type']=$fuel;self::assertSame($fuel,$registry->validate($data)['features']['fuel_type']);$controller->update($this->actor(1,['features'=>['fuel_type'=>$fuel]]),1);self::assertSame($fuel,$vehicle->fresh()->features['fuel_type']);}
        foreach(explode(' ',VehicleRegistry::COUNTRIES) as $country){$data=$base;$data['features']['operation_country']=$country;self::assertSame($country,$registry->validate($data)['features']['operation_country']);$controller->update($this->actor(1,['features'=>['operation_country'=>$country]]),1);self::assertSame($country,$vehicle->fresh()->features['operation_country']);}
        $data=$base;$data['features']['last_service']='2026-09-27';$data['features']['next_service']='2027-03-27';
        $data=$registry->validate($data);self::assertSame('2027-03-27',$data['features']['next_service']);
        $data['features']['next_service']=null;self::assertNull($registry->validate($data)['features']['next_service']);
        foreach([['next_service','not-a-date'],['last_service','2026-02-30'],['fuel_type','invented'],['operation_country','ZZ'],['gross_weight_kg',-100]] as [$key,$value]) {
            $data=$base;$data['features'][$key]=$value;
            try{$registry->validate($data);self::fail('Invalid input accepted: '.$key);}catch(ValidationException $e){self::assertNotEmpty($e->errors());}
        }
    }

    public function test_improvement_documents_and_drafts_reject_foreign_access():void
    {
        try{app(DocumentController::class)->upload($this->actor(4,['vehicle_id'=>2],'POST',['file'=>UploadedFile::fake()->create('x.pdf',1,'application/pdf')]));self::fail('Foreign vehicle upload accepted');}catch(HttpExceptionInterface $e){self::assertSame(403,$e->getStatusCode());}
        try{app(VehicleDraftController::class)->show($this->actor(4,['company_id'=>2]));self::fail('Foreign company draft accepted');}catch(HttpExceptionInterface $e){self::assertSame(403,$e->getStatusCode());}
    }
}
