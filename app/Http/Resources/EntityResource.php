<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EntityResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        $data=parent::toArray($request);
        if ($this->resource instanceof \App\Models\User) {
            if(((int)$request->user()?->id===(int)$this->resource->id||$request->is('api/auth/*'))&&\Illuminate\Support\Facades\Schema::hasTable('fleet_member_scopes')) {
                $data['can_access_fleet']=\Illuminate\Support\Facades\DB::table('fleet_member_scopes as s')->join('company_user as m',fn($q)=>$q->on('m.company_id','=','s.company_id')->on('m.user_id','=','s.user_id'))
                    ->where('s.user_id',$this->resource->id)->where('m.status','active')->get(['s.all_depots','s.depot_ids','s.vehicle_ids'])->contains(fn($s)=>$s->all_depots||count(json_decode($s->depot_ids,true)?:[])||count(json_decode($s->vehicle_ids??'[]',true)?:[]));
            }
            $team = app(\App\Services\CompanyTeamAccess::class);
            $data['can_manage_company_team'] = $this->resource->isSuperAdminOrMaster()
                || $team->companies($this->resource)->get(['id'])->contains(fn($company) => $team->manages($this->resource, (int)$company->id));
        }
        if ($this->resource instanceof \App\Models\CompanyMembership && $request->user()) {
            $team = app(\App\Services\CompanyTeamAccess::class);
            $data = array_merge($data, $team->organization($this->resource));
            $data['membership_role'] = $team->companyRole($this->resource->user, (int) $this->resource->company_id);
            $data['can_manage'] = $team->manages($request->user(), (int) $this->resource->company_id);
        }
        if($this->resource instanceof \App\Models\ShipmentWorkspace && $request->user()) {
            $data['operational_checklist']=app(\App\Services\Ops\OpsTaskAccess::class)->visibleWorkspace($request->user(),$this->resource,$this->resource->operational_checklist);
        }
        // Load responses embed the same workspace; apply the identical projection there too.
        if($this->resource instanceof \App\Models\Load && $request->user() && $this->resource->relationLoaded('shipmentWorkspace')) {
            $workspace=$this->resource->getRelation('shipmentWorkspace');
            if($workspace && isset($data['shipment_workspace']))$data['shipment_workspace']['operational_checklist']=app(\App\Services\Ops\OpsTaskAccess::class)->visibleWorkspace($request->user(),$workspace,$workspace->operational_checklist);
        }
        return $data;
    }
}
