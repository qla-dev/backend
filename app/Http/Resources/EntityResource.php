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
