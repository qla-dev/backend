<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Services\FormDrafts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FormDraftController extends Controller
{
    public function __construct(private FormDrafts $drafts){}
    private function context(Request $r):array {
        $d=$r->validate(['company_id'=>['nullable','integer','min:1'],'module'=>['required','regex:/^[a-zA-Z0-9_-]{1,80}$/'],'form_key'=>['required','string','max:120'],'record_key'=>['nullable','string','max:120']]);
        $this->drafts->authorize($r->user(),$d['company_id']??null);return $d;
    }
    public function index(Request $r) {
        $q=DB::table('form_drafts')->where('user_id',$r->user()->id)->where('state','in_progress')->orderByDesc('updated_at');
        $rows=$q->limit(200)->get()->filter(function($row)use($r){try{$this->drafts->authorize($r->user(),$row->company_id);return true;}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403)throw $e;return false;}});
        return response()->json(['data'=>$rows->map(fn($row)=>$this->drafts->present($row,false))->values()])->header('Cache-Control','no-store');
    }
    public function current(Request $r) {
        $d=$this->context($r);$scope=$this->drafts->scope($r->user(),$d);$row=DB::table('form_drafts')->where('active_scope',$scope)->first();
        if(!$row&&$r->boolean('latest_for_form')&&($d['record_key']??'new')==='new')$row=DB::table('form_drafts')->where(['user_id'=>$r->user()->id,'company_id'=>$d['company_id']??null,'module'=>$d['module'],'form_key'=>$d['form_key'],'state'=>'in_progress'])->latest('updated_at')->first();
        if(!$row&&$r->filled('recovery_id'))$row=DB::table('form_drafts')->where(['id'=>$r->input('recovery_id'),'user_id'=>$r->user()->id,'company_id'=>$d['company_id']??null,'module'=>$d['module'],'form_key'=>$d['form_key'],'record_key'=>$d['record_key']??'new'])->first();
        if($row&&$row->resource&&$row->resource_id){$record=$this->drafts->record($r->user(),$row->company_id,$row->resource,$row->resource_id);$result=$this->drafts->present($row);$result['current_record_hash']=$this->drafts->hash($record);$result['record_changed']=$result['current_record_hash']!==$row->base_hash;}else $result=$row?$this->drafts->present($row):null;
        return response()->json(['data'=>$result])->header('Cache-Control','no-store');
    }
    public function save(Request $r,string $id) {
        abort_unless(Str::isUuid($id),422);$d=$this->context($r);
        $v=$r->validate(['payload'=>['required','array'],'revision'=>['required','integer','min:0'],'resource'=>['nullable','in:'.implode(',',FormDrafts::RESOURCES)],'resource_id'=>['nullable','integer','min:1'],'base_version'=>['nullable','string','max:255']]);
        abort_if(strlen(json_encode($v['payload']))>2000000,422,'Draft exceeds the two megabyte form limit.');
        return DB::transaction(function()use($r,$id,$d,$v){
            DB::table('users')->where('id',$r->user()->id)->lockForUpdate()->firstOrFail();$scope=$this->drafts->scope($r->user(),$d);
            $row=DB::table('form_drafts')->where('id',$id)->lockForUpdate()->first();
            if($row){abort_unless($row->user_id===$r->user()->id&&$row->active_scope===$scope,409,'Draft is closed or belongs to another editing session.');abort_unless((int)$row->revision===(int)$v['revision'],409,'Draft changed in another tab. Review the newer draft before continuing.');}
            else {abort_if($v['revision']!==0||DB::table('form_drafts')->where('active_scope',$scope)->exists(),409,'An active draft already exists. Reload it before continuing.');}
            $values=['payload'=>$this->drafts->pack($v['payload']),'revision'=>($row->revision??0)+1,'updated_at'=>now()];
            if($row)DB::table('form_drafts')->where('id',$id)->update($values);
            else {
                $hash=null;if(!empty($v['resource'])&&!empty($v['resource_id'])){$hash=$this->drafts->hash($this->drafts->record($r->user(),$d['company_id']??null,$v['resource'],$v['resource_id']));if(!empty($v['base_version'])&&preg_match('/^[a-f0-9]{64}$/',$v['base_version']))$hash=$v['base_version'];}
                DB::table('form_drafts')->insert($values+['id'=>$id,'user_id'=>$r->user()->id,'company_id'=>$d['company_id']??null,'module'=>$d['module'],'form_key'=>$d['form_key'],'record_key'=>$d['record_key']??'new','active_scope'=>$scope,'state'=>'in_progress','resource'=>$v['resource']??null,'resource_id'=>$v['resource_id']??null,'base_hash'=>$hash,'base_version'=>$v['base_version']??null,'created_at'=>now()]);
            }
            $saved=DB::table('form_drafts')->where('id',$id)->first();$changed=$saved->resource&&$saved->resource_id?$this->drafts->hash($this->drafts->record($r->user(),$saved->company_id,$saved->resource,$saved->resource_id))!==$saved->base_hash:false;
            return response()->json(['data'=>['id'=>$id,'revision'=>$values['revision'],'state'=>'in_progress','record_changed'=>$changed]]);
        });
    }
    public function close(Request $r,string $id) {
        $v=$r->validate(['revision'=>['sometimes','integer','min:0']]);
        return DB::transaction(function()use($r,$id,$v){
            $row=DB::table('form_drafts')->where('id',$id)->where('user_id',$r->user()->id)->lockForUpdate()->first();abort_unless($row,404);$this->drafts->authorize($r->user(),$row->company_id);
            if(isset($v['revision']))abort_unless((int)$row->revision===(int)$v['revision'],409,'Draft changed in another tab. Review it before closing.');
            DB::table('form_drafts')->where('id',$id)->update(['active_scope'=>null,'state'=>$r->isMethod('DELETE')?'deleted':'completed','updated_at'=>now()]);
            return response()->json(['data'=>null]);
        });
    }
    public function rebase(Request $r,string $id) {
        $d=$r->validate(['revision'=>['required','integer'],'expected_hash'=>['required','regex:/^[a-f0-9]{64}$/']]);
        return DB::transaction(function()use($r,$id,$d){$row=DB::table('form_drafts')->where(['id'=>$id,'user_id'=>$r->user()->id])->lockForUpdate()->first();abort_unless($row,404);abort_unless($row->state==='in_progress'&&(int)$row->revision===(int)$d['revision']&&$row->resource&&$row->resource_id,409,'Draft changed.');$record=$this->drafts->record($r->user(),$row->company_id,$row->resource,$row->resource_id,true);$hash=$this->drafts->hash($record);abort_unless($hash===$d['expected_hash'],409,'Record changed again. Review it again.');DB::table('form_drafts')->where('id',$id)->update(['base_hash'=>$hash,'revision'=>$row->revision+1,'updated_at'=>now()]);return response()->json(['data'=>['revision'=>$row->revision+1]]);});
    }
}
