<?php
namespace App\Http\Middleware;
use App\Services\FormDrafts;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Optimistic record protection and retry-safe final submission for every integrated form. */
final class GuardFormDraftSubmission
{
    public function handle(Request $r,Closure $next) {
        $id=$r->header('X-Form-Draft');if(!$id||$r->isMethodSafe()||$r->is('api/form-drafts*'))return $next($r);
        abort_unless($r->user(),401);$service=app(FormDrafts::class);
        return DB::transaction(function()use($r,$next,$id,$service){
            $row=DB::table('form_drafts')->where('id',$id)->where('user_id',$r->user()->id)->lockForUpdate()->first();abort_unless($row,404);$service->authorize($r->user(),$row->company_id);
            $files=[];$readFiles=function($items,string $prefix='')use(&$files,&$readFiles){foreach($items as $name=>$file){$key=$prefix.$name;if(is_array($file))$readFiles($file,$key.'.');elseif($file instanceof \Illuminate\Http\UploadedFile)$files[$key]=hash_file('sha256',$file->getRealPath());}};$readFiles($r->allFiles());
            $hash=hash('sha256',json_encode([$r->method(),$r->path(),$r->except([]),$files],JSON_THROW_ON_ERROR));
            if($row->state==='completed'){abort_unless($row->request_hash===$hash&&$row->result,409,'This draft was already submitted.');abort_unless($row->access_hash===$service->accessHash($r->user(),$row->access_company_id),403,'Permissions changed since this submission. Refresh the module.');return response()->json($service->unpack($row->result),$row->result_status);}
            abort_unless($row->state==='in_progress'&&(int)$row->revision===(int)$r->header('X-Form-Draft-Revision'),409,'Draft changed. Reload before submitting.');
            if($row->resource&&$row->resource_id){$record=$service->record($r->user(),$row->company_id,$row->resource,$row->resource_id,true);if($service->hash($record)!==$row->base_hash)return response()->json(['code'=>'FORM_RECORD_CONFLICT','message'=>'The original record changed. Reload and review your draft before submitting.'],409);}
            $response=$next($r);
            if($response->getStatusCode()>=200&&$response->getStatusCode()<300&&$response instanceof \Illuminate\Http\JsonResponse){
                $data=$response->getData(true);$company=(int)($row->company_id??$data['data']['company_id']??$r->input('company_id')??$r->input('primary_company_id'))?:null;
                DB::table('form_drafts')->where('id',$id)->update(['state'=>'completed','active_scope'=>null,'request_hash'=>$hash,'result'=>$service->pack($data),'result_status'=>$response->getStatusCode(),'access_company_id'=>$company,'access_hash'=>$service->accessHash($r->user(),$company),'updated_at'=>now()]);
            }
            return $response;
        });
    }
}
