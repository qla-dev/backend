<?php
namespace Tests\Unit;
use App\Http\Controllers\Api\FormDraftController;
use App\Http\Middleware\GuardFormDraftSubmission;
use App\Models\User;
use App\Services\FormDrafts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FormDraftsTest extends VehicleRegistryTest
{
 protected function setUp():void {parent::setUp();config(['app.key'=>'base64:'.base64_encode(str_repeat('d',32))]);(require database_path('migrations/2026_11_20_000002_create_form_drafts.php'))->up();}
 private function request(int $actor,array $data=[],string $method='PUT',string $path='/api/form-drafts',array $headers=[]):Request {$r=Request::create($path,$method,$data);$r->setUserResolver(fn()=>User::findOrFail($actor));foreach($headers as $key=>$value)$r->headers->set($key,(string)$value);return $r;}
 private function body(string $module='loads',array $extra=[]):array {return array_replace(['company_id'=>1,'module'=>$module,'form_key'=>'editor','record_key'=>'new','revision'=>0,'payload'=>['form'=>['title'=>'Unfinished work','date'=>'2026-11-20','choices'=>['rear','side'],'document_ids'=>[12,13],'password'=>'never save this','cardNumber'=>'never save this either'],'step'=>2]],$extra);}
 private function denied(callable $fn,int $status):void {try{$fn();self::fail('Request should fail');}catch(HttpExceptionInterface $e){self::assertSame($status,$e->getStatusCode());}}
 public function test_global_drafts_cover_all_modules_without_creating_operational_records():void {
  $c=app(FormDraftController::class);$vehicles=DB::table('vehicles')->count();
  foreach(['vehicles','drivers','invoices','loads','shipments','customers','carriers','orders','contracts','expenses','maintenance','fuel','documents','future-module'] as $module){$id=(string)Str::uuid();$body=$this->body($module);$c->save($this->request(1,$body),$id);$saved=$c->current($this->request(1,$body,'GET'))->getData(true)['data'];self::assertSame($id,$saved['id']);self::assertSame(2,$saved['payload']['step']);self::assertSame(['rear','side'],$saved['payload']['form']['choices']);self::assertArrayNotHasKey('password',$saved['payload']['form']);self::assertArrayNotHasKey('cardNumber',$saved['payload']['form']);self::assertStringNotContainsString('Unfinished work',DB::table('form_drafts')->where('id',$id)->value('payload'));self::assertNull($c->current($this->request(3,$body,'GET'))->getData(true)['data']);}
  self::assertSame($vehicles,DB::table('vehicles')->count());self::assertCount(14,$c->index($this->request(1,[],'GET'))->getData(true)['data']);self::assertCount(0,$c->index($this->request(3,[],'GET'))->getData(true)['data']);
 }
 public function test_global_drafts_reject_duplicates_stale_revisions_and_deleted_sessions():void {
  $c=app(FormDraftController::class);$id=(string)Str::uuid();$body=$this->body();$c->save($this->request(1,$body),$id);
  $this->denied(fn()=>$c->save($this->request(1,$body),$id),409);$this->denied(fn()=>$c->save($this->request(1,$body),(string)Str::uuid()),409);
  $body['revision']=1;$body['payload']['form']['title']='Latest state';$c->save($this->request(1,$body),$id);self::assertSame(2,DB::table('form_drafts')->where('id',$id)->value('revision'));
  $this->denied(fn()=>$c->close($this->request(1,['revision'=>1],'DELETE'),$id),409);
  self::assertSame('in_progress',DB::table('form_drafts')->where('id',$id)->value('state'));
  $c->close($this->request(1,['revision'=>2],'DELETE'),$id);$body['revision']=2;$this->denied(fn()=>$c->save($this->request(1,$body),$id),409);self::assertCount(0,$c->index($this->request(1,[],'GET'))->getData(true)['data']);
 }
 public function test_global_drafts_enforce_company_and_record_boundaries_and_revocation():void {
  $c=app(FormDraftController::class);$this->denied(fn()=>$c->save($this->request(4,$this->body('invoices',['company_id'=>2,'resource'=>'vehicles','resource_id'=>1])),(string)Str::uuid()),403);
  $this->denied(fn()=>$c->save($this->request(1,$this->body('vehicles',['resource'=>'vehicles','resource_id'=>2])),(string)Str::uuid()),403);
  $id=(string)Str::uuid();$c->save($this->request(4,$this->body()),$id);DB::table('company_user')->where(['company_id'=>1,'user_id'=>4])->update(['status'=>'removed']);$this->denied(fn()=>$c->current($this->request(4,$this->body(),'GET')),403);
  $this->denied(fn()=>$c->close($this->request(3,[],'DELETE'),$id),404);
 }
 public function test_global_submission_keeps_original_record_and_requires_review_of_newer_changes():void {
  $c=app(FormDraftController::class);$id=(string)Str::uuid();$body=$this->body('vehicles',['resource'=>'vehicles','resource_id'=>1,'record_key'=>'1']);$c->save($this->request(1,$body),$id);
  DB::table('vehicles')->where('id',1)->update(['status'=>'maintenance']);$called=false;
  $r=$this->request(1,['registration_number'=>'Recovered edit'],'PUT','/api/vehicles/1',['X-Form-Draft'=>$id,'X-Form-Draft-Revision'=>1]);$middleware=app(GuardFormDraftSubmission::class);
  $response=$middleware->handle($r,function()use(&$called){$called=true;return response()->json(['data'=>[]]);});self::assertSame(409,$response->getStatusCode());self::assertFalse($called);self::assertSame('TEST-001',DB::table('vehicles')->where('id',1)->value('registration_number'));
  $current=$c->current($this->request(1,$body,'GET'))->getData(true)['data'];self::assertTrue($current['record_changed']);
  $rebased=$c->rebase($this->request(1,['revision'=>1,'expected_hash'=>$current['current_record_hash']]),$id)->getData(true)['data'];$r->headers->set('X-Form-Draft-Revision',(string)$rebased['revision']);
  $middleware->handle($r,function(){DB::table('vehicles')->where('id',1)->update(['registration_number'=>'Recovered edit']);return response()->json(['data'=>['id'=>1]]);});self::assertSame('Recovered edit',DB::table('vehicles')->where('id',1)->value('registration_number'));self::assertSame('completed',DB::table('form_drafts')->where('id',$id)->value('state'));
 }
 public function test_global_submission_retries_return_same_result_without_duplicate_writes():void {
  $c=app(FormDraftController::class);$id=(string)Str::uuid();$c->save($this->request(1,$this->body()),$id);$middleware=app(GuardFormDraftSubmission::class);
  $r=$this->request(1,['title'=>'Submit once'],'POST','/api/loads',['X-Form-Draft'=>$id,'X-Form-Draft-Revision'=>1]);$writes=0;$action=function()use(&$writes){$writes++;return response()->json(['data'=>['id'=>987,'title'=>'Submit once']],201);};
  $first=$middleware->handle($r,$action);$retry=$middleware->handle($r,$action);self::assertSame(1,$writes);self::assertSame($first->getData(true),$retry->getData(true));self::assertSame(201,$retry->getStatusCode());self::assertCount(0,$c->index($this->request(1,[],'GET'))->getData(true)['data']);
  $r->request->set('title','Different action');$this->denied(fn()=>$middleware->handle($r,$action),409);
 }
 public function test_global_failed_submission_retains_draft_for_recovery():void {
  $c=app(FormDraftController::class);$id=(string)Str::uuid();$c->save($this->request(1,$this->body()),$id);$r=$this->request(1,[],'POST','/api/loads',['X-Form-Draft'=>$id,'X-Form-Draft-Revision'=>1]);$middleware=app(GuardFormDraftSubmission::class);
  $middleware->handle($r,fn()=>response()->json(['message'=>'Validation failed'],422));self::assertSame('in_progress',DB::table('form_drafts')->where('id',$id)->value('state'));self::assertSame('Unfinished work',$c->current($this->request(1,$this->body(),'GET'))->getData(true)['data']['payload']['form']['title']);
 }
 public function test_global_source_version_detects_changes_before_first_autosave():void {
  $service=app(FormDrafts::class);$hash=$service->hash(DB::table('vehicles')->where('id',1)->first());DB::table('vehicles')->where('id',1)->update(['registration_number'=>'Changed by colleague']);$body=$this->body('vehicles',['resource'=>'vehicles','resource_id'=>1,'record_key'=>'1','base_version'=>$hash]);$c=app(FormDraftController::class);$response=$c->save($this->request(1,$body),(string)Str::uuid())->getData(true)['data'];self::assertTrue($response['record_changed']);self::assertSame('Changed by colleague',DB::table('vehicles')->where('id',1)->value('registration_number'));
 }
 public function test_global_cached_submission_does_not_survive_permission_revocation():void {
  $c=app(FormDraftController::class);$id=(string)Str::uuid();$c->save($this->request(4,$this->body()),$id);$r=$this->request(4,[],'POST','/api/loads',['X-Form-Draft'=>$id,'X-Form-Draft-Revision'=>1]);$m=app(GuardFormDraftSubmission::class);$m->handle($r,fn()=>response()->json(['data'=>['id'=>123]],201));DB::table('fleet_member_scopes')->where(['company_id'=>1,'user_id'=>4])->update(['depot_ids'=>'[]']);$this->denied(fn()=>$m->handle($r,fn()=>response()->json(['data'=>['id'=>456]],201)),403);
 }
 public function test_global_http_normalisation_preserves_unfinished_draft_strings():void {
  app(\Illuminate\Contracts\Http\Kernel::class);
  $request=$this->request(1,['payload'=>['title'=>'  still typing  ','blank'=>'']]);
  $pipeline=fn($r)=>(new \Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($r,fn($r)=>(new \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull)->handle($r,fn($r)=>$r));
  $result=$pipeline($request);self::assertSame('  still typing  ',$result->input('payload.title'));self::assertSame('',$result->input('payload.blank'));
  $normal=$pipeline($this->request(1,['title'=>'  ready  ','blank'=>''],'POST','/api/loads'));self::assertSame('ready',$normal->input('title'));self::assertNull($normal->input('blank'));
 }
}
