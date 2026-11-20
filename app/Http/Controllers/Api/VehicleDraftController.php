<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\FleetDepotAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleDraftController extends Controller
{
    private function scope(Request $r): string
    {
        $r->validate(['company_id'=>['nullable','integer','min:1','exists:companies,id']]);
        if ($r->filled('company_id')) app(FleetDepotAccess::class)->authorize($r->user(), $r->integer('company_id'), 'create');
        return $r->user()->id.':'.$r->integer('company_id');
    }

    public function show(Request $r)
    {
        $row=DB::table('vehicle_drafts')->where('active_scope',$this->scope($r))->first();
        if (!$row && $r->filled('recovery_id')) $row=DB::table('vehicle_drafts')->where('id',$r->input('recovery_id'))->where('user_id',$r->user()->id)->where('company_id',$r->integer('company_id')?:null)->first();
        if ($row) $row->form=json_decode($row->form,true);
        return response()->json(['data'=>$row]);
    }

    public function save(Request $r, string $id)
    {
        $r->validate(['id'=>['prohibited'],'form'=>['required','array'],'revision'=>['required','integer','min:0']]);
        abort_unless(\Illuminate\Support\Str::isUuid($id),422);
        abort_if(strlen(json_encode($r->input('form')))>250000,422,'Draft is too large.');
        $scope=$this->scope($r);
        return DB::transaction(function () use ($r,$id,$scope) {
            // Serialise initial creation for this account, including two simultaneously opened tabs.
            DB::table('users')->where('id',$r->user()->id)->lockForUpdate()->firstOrFail();
            $row=DB::table('vehicle_drafts')->where('id',$id)->lockForUpdate()->first();
            if ($row) {
                abort_unless((int)$row->user_id===(int)$r->user()->id && (int)$row->company_id===$r->integer('company_id'),404);
                abort_if($row->deleted_at || $row->vehicle_id,409,'Draft is already closed.');
                abort_if((int)$row->revision!==$r->integer('revision'),409,'Draft changed in another tab. Reload to continue.');
            } else {
                abort_if(DB::table('vehicle_drafts')->where('active_scope',$scope)->exists(),409,'An active draft already exists. Reload to continue.');
                abort_if($r->integer('revision')!==0,409,'Draft no longer exists.');
            }
            $revision=($row->revision??0)+1;
            $values=['form'=>json_encode($r->input('form')),'revision'=>$revision,'updated_at'=>now()];
            if ($row) DB::table('vehicle_drafts')->where('id',$id)->update($values);
            else DB::table('vehicle_drafts')->insert($values+['id'=>$id,'user_id'=>$r->user()->id,'company_id'=>$r->integer('company_id')?:null,'active_scope'=>$scope,'created_at'=>now()]);
            return response()->json(['data'=>['id'=>$id,'revision'=>$revision]]);
        });
    }

    public function destroy(Request $r, string $id)
    {
        $scope=$this->scope($r);
        DB::table('vehicle_drafts')->where('id',$id)->where('active_scope',$scope)->update(['active_scope'=>null,'deleted_at'=>now(),'updated_at'=>now()]);
        return response()->json(['data'=>null]);
    }
}
