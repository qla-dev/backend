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
