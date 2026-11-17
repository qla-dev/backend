<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\FleetOperationsController;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\FleetOperations;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FleetOperationsTest extends CompanyTeamAccessTest
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('drivers',function(Blueprint $t){$t->id();$t->unsignedBigInteger('user_id');$t->unsignedBigInteger('primary_company_id')->nullable();});
        Schema::create('vehicles',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->unsignedBigInteger('assigned_driver_user_id')->nullable();$t->string('registration_number');$t->string('ownership_type')->default('owned');$t->string('status')->default('idle');$t->timestamps();});
        Schema::table('loads',function(Blueprint $t){$t->unsignedBigInteger('vehicle_id')->nullable();$t->unsignedBigInteger('assigned_driver_user_id')->nullable();$t->string('status')->default('in_delivery');$t->json('status_change')->nullable();$t->string('booking_status')->nullable();$t->timestamp('completed_at')->nullable();$t->timestamps();});
        (require database_path('migrations/2026_08_30_000003_create_vehicle_return_inspections.php'))->up();
        (require database_path('migrations/2026_09_14_000001_add_parking_coordinates_to_vehicle_return_inspections.php'))->up();
        DB::table('users')->insert([['id'=>6,'name'=>'Driver A','role_id'=>9],['id'=>7,'name'=>'Driver B','role_id'=>9],['id'=>8,'name'=>'Foreign Driver','role_id'=>9]]);
        foreach([6,7] as $id) DB::table('company_user')->insert(['company_id'=>1,'user_id'=>$id,'status'=>'active','company_role_id'=>9]);
        DB::table('company_user')->insert(['company_id'=>2,'user_id'=>8,'status'=>'active','company_role_id'=>9]);
        DB::table('vehicles')->insert([['id'=>1,'company_id'=>1,'registration_number'=>'TEST-001','assigned_driver_user_id'=>6,'ownership_type'=>'owned','status'=>'in_use'],['id'=>2,'company_id'=>2,'registration_number'=>'OTHER-001','assigned_driver_user_id'=>8,'ownership_type'=>'owned','status'=>'in_use']]);
        (require database_path('migrations/2026_11_12_000001_create_fleet_operations.php'))->up();
        (require database_path('migrations/2026_11_17_000001_add_fleet_depots_and_access.php'))->up();
        DB::table('fleet_depots')->insert([['id'=>10,'company_id'=>1,'code'=>'BER','name'=>'Berlin','active'=>true],['id'=>11,'company_id'=>1,'code'=>'LEI','name'=>'Leipzig','active'=>true],['id'=>12,'company_id'=>2,'code'=>'OTH','name'=>'Other','active'=>true]]);
        DB::table('vehicles')->where('id',1)->update(['depot_id'=>10]);
        DB::table('vehicles')->where('id',2)->update(['depot_id'=>12]);
    }

    private function req(int $actor,array $data=[]):Request{$r=Request::create('/fleet','POST',$data);$r->setUserResolver(fn()=>User::findOrFail($actor));return $r;}
    private function transfer(array $overrides=[]):int{return DB::transaction(fn()=>app(FleetOperations::class)->handover(Vehicle::findOrFail(1),1,array_merge(['destination'=>'driver','to_driver_id'=>7,'mileage_km'=>100,'fuel_level_percent'=>50,'has_damage'=>false],$overrides)));}
    private function tour(array $overrides=[]):int{return DB::table('fleet_tours')->insertGetId(array_merge(['company_id'=>1,'service_date'=>now()->toDateString(),'depot_id'=>10,'depot_name'=>'Berlin','partner'=>'Partner','tour_code'=>'CA-01','driver_id'=>6,'vehicle_id'=>1,'created_by'=>1,'created_at'=>now(),'updated_at'=>now()],$overrides));}

    public function test_transfer_changes_custody_without_finishing_load():void{
        DB::table('loads')->insert(['id'=>9,'company_id'=>1]);
        $id=$this->transfer(['load_id'=>9]);
        self::assertSame(7,(int)Vehicle::find(1)->assigned_driver_user_id);
        self::assertNotNull(DB::table('fleet_assignments')->where('driver_id',6)->value('ended_at'));
        self::assertSame('pending',DB::table('fleet_handovers')->find($id)->review_status);
        self::assertTrue(DB::table('loads')->where('id',9)->exists());
    }
    public function test_previous_day_assignment_locks_without_new_handover():void{
        DB::table('fleet_assignments')->where('vehicle_id',1)->update(['started_at'=>now()->subDays(2)]);
        $id=$this->tour();app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
        self::assertSame('locked',DB::table('fleet_tours')->find($id)->status);self::assertSame(0,DB::table('fleet_handovers')->count());
    }
    public function test_pending_review_blocks_lock():void{
        $this->transfer();$id=$this->tour(['driver_id'=>7]);
        $this->expectException(HttpException::class);app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
    }
    public function test_clear_review_unlocks_tour_and_is_not_repeatable():void{
        $h=$this->transfer();$c=app(FleetOperationsController::class);$c->review($this->req(1,['decision'=>'clear']),1,$h);
        $id=$this->tour(['driver_id'=>7]);app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
        self::assertSame('locked',DB::table('fleet_tours')->find($id)->status);
        $this->expectException(HttpException::class);$c->review($this->req(1,['decision'=>'clear']),1,$h);
    }
    public function test_damage_completes_review_and_remains_separate_case():void{
        $h=$this->transfer();$response=app(FleetOperationsController::class)->review($this->req(1,['decision'=>'damage','description'=>'Mirror scratch','block_vehicle'=>false]),1,$h)->getData(true);
        self::assertSame('confirmed',DB::table('fleet_handovers')->find($h)->review_status);
        self::assertSame('reported',DB::table('fleet_damages')->find($response['data']['damage_id'])->status);
        self::assertSame(1,DB::table('fleet_notifications')->count());self::assertFalse((bool)Vehicle::find(1)->dispatch_blocked);
    }
    public function test_serious_damage_blocks_dispatch_after_review():void{
        $h=$this->transfer();app(FleetOperationsController::class)->review($this->req(1,['decision'=>'damage','description'=>'Brake damage','block_vehicle'=>true]),1,$h);
        self::assertTrue((bool)Vehicle::find(1)->dispatch_blocked);$id=$this->tour(['driver_id'=>7]);
        $this->expectException(HttpException::class);app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
    }
    public function test_internal_case_needs_no_police_or_insurance():void{
        $h=$this->transfer();$d=app(FleetOperations::class)->damage(1,1,1,$h,['description'=>'Parking scratch']);
        app(FleetOperationsController::class)->updateDamage($this->req(1,['status'=>'closed','kind'=>'internal','description'=>'Parking scratch','details'=>['driver_statement'=>'My statement','resolution'=>'Repaired internally']]),1,$d);
        self::assertSame('closed',DB::table('fleet_damages')->find($d)->status);
    }
    public function test_company_boundary_rejects_foreign_driver():void{$this->expectException(HttpException::class);$this->transfer(['to_driver_id'=>8]);}
    public function test_driver_cannot_review_or_read_damage_case():void{
        $h=$this->transfer();$this->expectException(HttpException::class);app(FleetOperationsController::class)->review($this->req(7,['decision'=>'clear']),1,$h);
    }
    public function test_foreign_company_is_not_visible():void{$this->expectException(HttpException::class);app(FleetOperationsController::class)->context($this->req(1),2);}
    public function test_mileage_cannot_decrease():void{
        $this->transfer(['destination'=>'depot']);$this->expectException(\Illuminate\Validation\ValidationException::class);$this->transfer(['mileage_km'=>90]);
    }
    public function test_rented_vehicle_return_archives_and_ends_assignment():void{
        Vehicle::find(1)->update(['ownership_type'=>'rented']);$this->transfer(['destination'=>'returned']);
        self::assertSame('returned',Vehicle::find(1)->status);self::assertNull(Vehicle::find(1)->assigned_driver_user_id);self::assertSame(0,DB::table('fleet_assignments')->where('vehicle_id',1)->whereNull('ended_at')->count());
    }
    public function test_owned_vehicle_cannot_be_returned_as_rental():void{$this->expectException(HttpException::class);$this->transfer(['destination'=>'returned']);}
    public function test_import_preview_is_read_only_and_confirmation_deduplicates():void{
        $payload=['service_date'=>now()->toDateString(),'depot_id'=>10,'depot_name'=>'Berlin','partner'=>'P','rows'=>[['tour_code'=>'001'],['tour_code'=>'001'],['tour_code'=>'']],'confirm'=>false];
        $c=app(FleetOperationsController::class);$preview=$c->importTours($this->req(1,$payload),1)->getData(true)['data'];
        self::assertCount(1,$preview['valid']);self::assertCount(1,$preview['duplicates']);self::assertCount(1,$preview['invalid']);self::assertSame(0,DB::table('fleet_tours')->count());
        $payload['confirm']=true;$c->importTours($this->req(1,$payload),1);$c->importTours($this->req(1,$payload),1);self::assertSame(1,DB::table('fleet_tours')->count());self::assertSame('001',DB::table('fleet_tours')->first()->tour_code);
    }
    public function test_same_tour_id_can_exist_on_different_days_or_depots():void{
        $c=app(FleetOperationsController::class);$p=['service_date'=>now()->toDateString(),'depot_id'=>10,'depot_name'=>'Berlin','partner'=>'P','rows'=>[['tour_code'=>'1']],'confirm'=>true];
        $c->importTours($this->req(1,$p),1);$p['service_date']=now()->subDay()->toDateString();$c->importTours($this->req(1,$p),1);$p['depot_name']='Leipzig';$p['depot_id']=11;$c->importTours($this->req(1,$p),1);self::assertSame(3,DB::table('fleet_tours')->count());
    }
    public function test_mismatched_driver_cannot_lock():void{$id=$this->tour(['driver_id'=>7]);$this->expectException(HttpException::class);app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);}
    public function test_change_driver_after_lock_blocks_completion_until_corrected():void{
        $id=$this->tour();app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);$this->transfer();
        $this->expectException(HttpException::class);app(FleetOperationsController::class)->tourAction($this->req(1),1,$id,'complete');
    }
    public function test_reopen_requires_reason_and_preserves_old_snapshot_in_audit():void{
        $id=$this->tour();app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
        app(FleetOperationsController::class)->tourAction($this->req(1,['reason'=>'Vehicle replacement']),1,$id,'reopen');
        self::assertSame('open',DB::table('fleet_tours')->find($id)->status);self::assertStringContainsString('TEST-001',DB::table('fleet_events')->where('action','reopened')->value('details'));
    }
    public function test_year_old_archive_is_paginated_and_company_scoped():void{
        $date=now()->subYear()->toDateString();$this->tour(['service_date'=>$date]);$this->tour(['company_id'=>2,'tour_code'=>'OTHER']);
        $r=app(FleetOperationsController::class)->tourList($this->req(1,['from'=>$date,'to'=>now()->toDateString()]),1)->getData(true)['data'];self::assertSame(1,$r['total']);
    }
    public function test_daily_tour_can_be_locked_after_reviewed_depot_return():void{
        $id=$this->tour();$h=$this->transfer(['destination'=>'depot','depot_name'=>'Berlin']);
        app(FleetOperationsController::class)->review($this->req(1,['decision'=>'clear']),1,$h);
        app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
        app(FleetOperationsController::class)->tourAction($this->req(1),1,$id,'complete');
        self::assertSame('completed',DB::table('fleet_tours')->find($id)->status);
        self::assertNull(Vehicle::find(1)->assigned_driver_user_id);
    }
    public function test_unreviewed_return_blocks_daily_tour_closure():void{
        $id=$this->tour();$this->transfer(['destination'=>'depot','depot_name'=>'Berlin']);
        $this->expectException(HttpException::class);app(FleetOperations::class)->lockTour(DB::table('fleet_tours')->find($id),1);
    }
    public function test_existing_damage_can_be_linked_without_duplicate_case():void{
        $h=$this->transfer(['has_damage'=>true,'notes'=>'Known scratch']);
        $case=app(FleetOperations::class)->damage(1,1,1,null,['description'=>'Known scratch']);
        app(FleetOperationsController::class)->review($this->req(1,['decision'=>'clear','existing_damage_id'=>$case]),1,$h);
        self::assertSame(1,DB::table('fleet_damages')->count());self::assertSame($case,(int)DB::table('fleet_handovers')->find($h)->damage_id);
    }
    public function test_import_mapping_uses_runtime_skill_and_rejects_invalid_indexes():void{
        config(['services.openrouter.api_key'=>'test-only','services.openrouter.url'=>'https://fleet-test.invalid','services.openrouter.model'=>'test-model']);
        \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['choices'=>[['message'=>['content'=>'{"id_column":0,"driver_column":1,"date_column":null}']]]])]);
        $mapping=app(\App\Services\FleetImportMapping::class)->suggest(['Tour ID','Driver'],[['001','A']]);self::assertSame(0,$mapping['id_column']);
        \Illuminate\Support\Facades\Http::assertSent(fn($r)=>str_contains($r['messages'][0]['content'],'fleet tour import')&&str_contains($r['messages'][1]['content'],'001'));
        \Illuminate\Support\Facades\Http::fake(['*'=>\Illuminate\Support\Facades\Http::response(['choices'=>[['message'=>['content'=>'{"id_column":99,"driver_column":null,"date_column":null}']]]])]);
        $this->expectException(HttpException::class);app(\App\Services\FleetImportMapping::class)->suggest(['Tour ID'],[['001']]);
    }
    public function test_mapping_is_saved_per_company_partner_and_headers():void{
        $p=['partner'=>'P','headers'=>['Tour ID'],'mapping'=>['id_column'=>0,'driver_column'=>null,'date_column'=>null]];
        $c=app(FleetOperationsController::class);$c->mapping($this->req(1,$p),1);unset($p['mapping']);
        self::assertSame(0,$c->mapping($this->req(1,$p),1)->getData(true)['data']['id_column']);
        self::assertNull($c->mapping($this->req(2,$p),2)->getData(true)['data']);
    }
    public function test_damage_notifications_are_company_scoped_and_marked_read():void{
        $case=app(FleetOperations::class)->damage(1,1,1,null,['description'=>'Scratch']);$c=app(FleetOperationsController::class);
        self::assertCount(1,$c->notifications($this->req(1))->getData(true)['data']);self::assertCount(0,$c->notifications($this->req(2))->getData(true)['data']);
        $c->readNotification($this->req(1),1,DB::table('fleet_notifications')->value('id'));self::assertCount(0,$c->notifications($this->req(1))->getData(true)['data']);
    }
    public function test_unavailable_vehicle_cannot_be_dispatched_in_other_load_workflows():void{
        Vehicle::find(1)->update(['status'=>'maintenance']);$this->expectException(HttpException::class);app(FleetOperations::class)->assertDispatchable(1);
    }
    public function test_new_handover_saves_private_photos_without_a_load():void{
        \Illuminate\Support\Facades\Storage::fake('local');
        $r=$this->req(1,['vehicle_id'=>1,'destination'=>'driver','to_driver_id'=>7,'mileage_km'=>100,'fuel_level_percent'=>50,'has_damage'=>false]);
        $r->files->set('photos',array_map(fn($side)=>\Illuminate\Http\UploadedFile::fake()->create($side.'.jpg',1,'image/jpeg'),['front','rear','side']));
        $result=app(FleetOperationsController::class)->createHandover($r,1)->getData(true);
        self::assertGreaterThan(0,$result['data']['id']);self::assertSame(3,DB::table('fleet_files')->count());self::assertSame(7,(int)Vehicle::find(1)->assigned_driver_user_id);
        foreach(DB::table('fleet_files')->get() as $file)\Illuminate\Support\Facades\Storage::disk('local')->assertExists($file->path);
    }
    public function test_damage_attachments_cannot_be_downloaded_by_another_company():void{
        \Illuminate\Support\Facades\Storage::fake('local');$case=app(FleetOperations::class)->damage(1,1,1,null,['description'=>'Scratch']);
        $r=$this->req(1);$r->files->set('files',[\Illuminate\Http\UploadedFile::fake()->create('statement.txt',1,'text/plain')]);
        $c=app(FleetOperationsController::class);$c->upload($r,1,$case);
        self::assertSame(1,DB::table('fleet_files')->count());$id=DB::table('fleet_files')->value('id');
        $this->expectException(HttpException::class);$c->file($this->req(2),1,$id);
    }
    public function test_return_on_partner_load_does_not_change_partner_vehicle_custody():void{
        \Illuminate\Support\Facades\Storage::fake('local');
        $loadId=DB::table('loads')->insertGetId(['company_id'=>1,'vehicle_id'=>2,'assigned_driver_user_id'=>8]);$load=\App\Models\Load::findOrFail($loadId);
        $r=$this->req(1,['mileage_km'=>100,'fuel_level_percent'=>50,'has_damage'=>false]);
        $r->files->set('photos',array_map(fn($side)=>\Illuminate\Http\UploadedFile::fake()->create($side.'.jpg',1,'image/jpeg'),['front','rear','side']));
        app(\App\Http\Controllers\Api\VehicleReturnInspectionController::class)->store($r,$load);
        self::assertSame(8,(int)Vehicle::find(2)->assigned_driver_user_id);self::assertSame('in_use',Vehicle::find(2)->status);self::assertSame(0,DB::table('fleet_handovers')->count());
        self::assertSame('finished',$load->fresh()->status);
    }
}
