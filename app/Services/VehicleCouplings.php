<?php

namespace App\Services;

use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class VehicleCouplings
{
    public static function assertDispatchableCombination(Vehicle $v): void
    {
        $tractor=($v->features['resource_type']??'')==='tractor_unit';
        if(!Schema::hasTable('fleet_vehicle_couplings')){abort_if($tractor,422,'Attach a registered semi-trailer before dispatching this tractor unit.');return;}
        $trailers=DB::table('fleet_vehicle_couplings as c')->join('vehicles as t','t.id','=','c.trailer_vehicle_id')->where('c.towing_vehicle_id',$v->id)->where('c.company_id',$v->company_id)->whereNull('c.ended_at')->get(['t.status','t.dispatch_blocked','t.features']);
        abort_if($tractor&&$trailers->isEmpty(),422,'Attach a registered semi-trailer before dispatching this tractor unit.');
        foreach($trailers as $trailer)abort_if($trailer->dispatch_blocked||!in_array($trailer->status,FleetOperations::ACTIVE_STATUSES,true),422,'A coupled trailer is unavailable for dispatch.');
    }
    public static function assertDetached(Vehicle $v): void
    {
        if(!Schema::hasTable('fleet_vehicle_couplings'))return;
        abort_if(DB::table('fleet_vehicle_couplings')->whereNull('ended_at')->where(fn($q)=>$q->where('towing_vehicle_id',$v->id)->orWhere('trailer_vehicle_id',$v->id))->exists(),422,'Detach the coupled vehicle or trailer before changing its classification, depot or availability.');
    }
}
