<?php
namespace Tests\Unit;
use App\Http\Controllers\Api\{FleetWorkflowController,FleetOperationsController,CrmLogisticsController};
use App\Models\{User,Vehicle};
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\{Request,UploadedFile};
use Illuminate\Support\Facades\{DB,Schema,Storage};
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FreightbookWorkflowTest extends VehicleRegistryTest {
 protected function setUp():void {
  parent::setUp();Schema::create('crm_documents',function(Blueprint $t){$t->id();$t->unsignedBigInteger('company_id');$t->string('source');$t->string('stage');$t->unsignedBigInteger('load_id')->nullable();$t->string('currency',3);$t->date('issued_on')->nullable();$t->timestamps();});
  (require database_path('migrations/2026_11_19_000002_create_freightbook_workflow_records.php'))->up();
  DB::table('crm_documents')->insert(['id'=>1,'company_id'=>1,'source'=>'smartfreight','stage'=>'lead','currency'=>'EUR']);
 }
 private function reqWorkflow(array $data=[],int $user=1):Request{$r=Request::create('/test','POST',$data);$r->setUserResolver(fn()=>User::findOrFail($user));return $r;}
    private function rejects(int $status,callable $fn):void{try{$fn();self::fail('Request was accepted');}catch(\Illuminate\Database\Eloquent\ModelNotFoundException $e){self::assertSame(404,$status);}catch(HttpExceptionInterface $e){self::assertSame($status,$e->getStatusCode());}}
 public function test_retry_returns_same_handover_without_duplicate_photos_and_changed_body_conflicts():void {
  Storage::fake('local');$c=app(FleetOperationsController::class);$body=['request_key'=>'11111111-1111-4111-8111-111111111111','vehicle_id'=>1,'destination'=>'depot','depot_id'=>10,'mileage_km'=>100,'fuel_level_percent'=>50,'has_damage'=>false];
  $request=function($body){$r=$this->reqWorkflow($body);$r->files->set('photos',array_map(fn($side)=>UploadedFile::fake()->create($side.'.jpg',1,'image/jpeg'),['front','rear','side']));return $r;};
  $a=$c->createHandover($request($body),1)->getData(true)['data']['id'];$b=$c->createHandover($request($body),1)->getData(true)['data']['id'];self::assertSame($a,$b);self::assertSame(1,DB::table('fleet_handovers')->count());self::assertSame(3,DB::table('fleet_files')->count());self::assertSame(1,DB::table('fleet_handover_requests')->count());
  $this->rejects(409,fn()=>$c->createHandover($request(array_merge($body,['mileage_km'=>101])),1));
 }
 public function test_registry_review_rejects_unconfirmed_data_and_becomes_stale_after_edit():void {
  $c=app(FleetWorkflowController::class);$v=Vehicle::find(1);$this->rejects(422,fn()=>$c->review($this->reqWorkflow(['fingerprint'=>$c::fingerprint($v),'evidence_reference'=>'REG-001']),1,1));
  $v->update(['transport_type'=>'road','capacity_kg'=>1000,'capacity_m3'=>10,'features'=>['resource_type'=>'cargo_van','body_type_code'=>'panel_van','legal_category'=>'N1','gross_weight_kg'=>3500,'unladen_weight_kg'=>2500]]);
  $c->review($this->reqWorkflow(['fingerprint'=>$c::fingerprint($v),'evidence_reference'=>'REG-001']),1,1);self::assertTrue($c->index($this->reqWorkflow(),1,1)->getData(true)['data']['review']['current']);
  $v->update(['capacity_kg'=>900]);self::assertFalse($c->index($this->reqWorkflow(),1,1)->getData(true)['data']['review']['current']);
 }
 public function test_service_deadline_is_scoped_and_cannot_assign_foreign_member():void {
  $c=app(FleetWorkflowController::class);$d=['kind'=>'service','title'=>'Oil change','due_on'=>now()->toDateString(),'responsible_user_id'=>1];$c->storeTask($this->reqWorkflow($d),1,1);self::assertTrue($c->index($this->reqWorkflow(),1,1)->getData(true)['data']['tasks'][0]['due']);
  $this->rejects(422,fn()=>$c->storeTask($this->reqWorkflow(array_replace($d,['responsible_user_id'=>2])),1,1));
  $this->rejects(404,fn()=>$c->storeTask($this->reqWorkflow($d),1,2));
 }
 public function test_alerts_do_not_expose_foreign_depot_tasks():void {
  $c=app(FleetWorkflowController::class);foreach([1,3] as $id)DB::table('fleet_vehicle_tasks')->insert(['company_id'=>1,'vehicle_id'=>$id,'kind'=>'service','title'=>'Deadline '.$id,'due_on'=>now()->toDateString(),'responsible_user_id'=>1,'created_by'=>1]);
  $rows=$c->alerts($this->reqWorkflow([],4),1)->getData(true)['data']['data'];self::assertCount(1,$rows);self::assertSame(1,$rows[0]['vehicle_id']);
 }
 private function logistics():CrmLogisticsController {
  DB::table('accounting_permissions')->insert(['company_id'=>1,'user_id'=>1,'abilities'=>'["crm"]','granted_by'=>1]);
  $c=app(CrmLogisticsController::class);$c->save($this->reqWorkflow(['expected_revision'=>0,'request'=>['origin'=>'Sarajevo','destination'=>'Berlin','mode'=>'road','cargo'=>'10 pallets']]),1,1);return $c;
 }
 public function test_quote_cost_margin_and_accepted_snapshot_are_preserved():void {
  $c=$this->logistics();$q=$c->supplier($this->reqWorkflow(['supplier_name'=>'Carrier','status'=>'received','currency'=>'EUR','cost_total'=>'1000.00','valid_until'=>now()->addDays(10)->toDateString()]),1,1)->getData(true)['data']['id'];
  $v=$c->version($this->reqWorkflow(['supplier_quote_id'=>$q,'additional_cost'=>'50.00','sales_total'=>'1300.00','valid_until'=>now()->addDays(5)->toDateString()]),1,1)->getData(true)['data']['id'];
  $snapshot=json_decode(DB::table('crm_quote_versions')->find($v)->snapshot,true);self::assertSame('1050.00',$snapshot['total_cost']);self::assertSame('250.00',$snapshot['margin']);
  $c->accept($this->reqWorkflow(['acceptance_reference'=>'Customer email 19 Nov']),1,1,$v);$c->accept($this->reqWorkflow(['acceptance_reference'=>'Customer email 19 Nov']),1,1,$v);self::assertSame(1,DB::table('crm_quote_versions')->count());
  $board=app(\App\Services\Crm\CrmPipeline::class)->documents(1,[]);self::assertSame('1300.00',$board[0]->accepted_customer_quote['sales_total']);
  $link=app(\App\Services\Crm\CrmLoadLink::class);DB::transaction(fn()=>$link->assertAvailable(User::find(1),1,1,$v));
  $this->rejects(409,fn()=>DB::transaction(fn()=>$link->assertAvailable(User::find(1),1,1,$v+1)));
  $this->rejects(409,fn()=>$c->save($this->reqWorkflow(['expected_revision'=>2,'request'=>['origin'=>'Changed','destination'=>'Berlin','mode'=>'road','cargo'=>'10 pallets']]),1,1));
  $c->reopen($this->reqWorkflow(['reason'=>'Customer requested new date']),1,1);self::assertNotNull(DB::table('crm_quote_versions')->find($v)->accepted_at);self::assertNull(DB::table('crm_logistics_cases')->value('accepted_version_id'));
 }
 public function test_cross_company_crm_request_is_rejected():void{$c=$this->logistics();$this->rejects(403,fn()=>$c->index($this->reqWorkflow([],2),1,1));}
 public function test_conversion_rejects_existing_job_instead_of_silently_unlinking():void {
  $this->logistics();DB::table('crm_documents')->where('id',1)->update(['load_id'=>99]);
  $this->rejects(409,fn()=>DB::transaction(fn()=>app(\App\Services\Crm\CrmLoadLink::class)->assertAvailable(User::find(1),1,1,null)));
 }
 public function test_foreign_currency_needs_rate_and_date_and_negative_margin_needs_confirmation():void {
  $c=$this->logistics();$d=['supplier_name'=>'Foreign','status'=>'received','currency'=>'USD','cost_total'=>'1000.00','valid_until'=>now()->addDays(10)->toDateString()];
  $this->rejects(422,fn()=>$c->supplier($this->reqWorkflow($d),1,1));
  $id=$c->supplier($this->reqWorkflow($d+['fx_rate'=>'0.90','fx_on'=>now()->toDateString()]),1,1)->getData(true)['data']['id'];
  $v=['supplier_quote_id'=>$id,'additional_cost'=>'10.00','sales_total'=>'800.00','valid_until'=>now()->addDays(3)->toDateString()];
  $this->rejects(422,fn()=>$c->version($this->reqWorkflow($v),1,1));$c->version($this->reqWorkflow($v+['allow_negative_margin'=>true]),1,1);
  self::assertSame('-110.00',json_decode(DB::table('crm_quote_versions')->value('snapshot'),true)['margin']);
 }
 public function test_reviewed_document_still_warns_on_expiry_and_retains_resolution_history():void {
  $c=app(FleetWorkflowController::class);$doc=DB::table('documents')->insertGetId(['uploaded_by_user_id'=>1,'vehicle_id'=>1,'name'=>'registration.pdf','type'=>'REGISTRATION','path'=>'registration.pdf']);
  $id=$c->storeTask($this->reqWorkflow(['kind'=>'document','title'=>'Registration','document_id'=>$doc,'due_on'=>now()->subDay()->toDateString(),'responsible_user_id'=>1]),1,1)->getData(true)['data']['id'];
  $c->updateTask($this->reqWorkflow(['action'=>'review','reason'=>'Compared with original REG-001']),1,1,$id);
  $row=$c->index($this->reqWorkflow(),1,1)->getData(true)['data']['tasks'][0];self::assertSame('reviewed',$row['review_status']);self::assertTrue($row['due']);
  $c->updateTask($this->reqWorkflow(['action'=>'complete','reason'=>'Replaced by new registration']),1,1,$id);self::assertSame('done',DB::table('fleet_vehicle_tasks')->find($id)->status);self::assertSame(2,DB::table('fleet_events')->where('action','like','task_%')->where('action','!=','task_created')->count());
 }
 public function test_old_quote_cannot_be_accepted_after_request_revision():void {
  $c=$this->logistics();$q=$c->supplier($this->reqWorkflow(['supplier_name'=>'Carrier','status'=>'received','currency'=>'EUR','cost_total'=>'1000','valid_until'=>now()->addDays(10)->toDateString()]),1,1)->getData(true)['data']['id'];
  $v=$c->version($this->reqWorkflow(['supplier_quote_id'=>$q,'additional_cost'=>'0','sales_total'=>'1200','valid_until'=>now()->addDays(5)->toDateString()]),1,1)->getData(true)['data']['id'];
  $c->save($this->reqWorkflow(['expected_revision'=>2,'request'=>['origin'=>'Sarajevo','destination'=>'Hamburg','mode'=>'road','cargo'=>'Changed destination']]),1,1);
  $this->rejects(409,fn()=>$c->accept($this->reqWorkflow(['acceptance_reference'=>'Old quote email']),1,1,$v));self::assertNull(DB::table('crm_logistics_cases')->value('accepted_version_id'));
 }
}
