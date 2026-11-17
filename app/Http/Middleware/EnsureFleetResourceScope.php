<?php

namespace App\Http\Middleware;

use App\Models\Vehicle;
use App\Services\FleetDepotAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Apply the same boundary to older generic resource / document / GPS routes. */
final class EnsureFleetResourceScope
{
    public function handle(Request $r,Closure $next)
    {
        $access=app(FleetDepotAccess::class);$user=$r->user();
        if(!$user)return $next($r);
        $path=$r->path();$vehicle=null;$originalVehicle=null;
        if(preg_match('~^api/vehicles/(\d+)(?:/|$)~',$path,$m))$vehicle=Vehicle::findOrFail($m[1]);
        if(preg_match('~^api/documents/(\d+)(?:/|$)~',$path,$m)) {
            $doc=DB::table('documents')->where('id',$m[1])->firstOrFail();
            if($doc->vehicle_id)$vehicle=Vehicle::findOrFail($doc->vehicle_id);
        }
        $originalVehicle=$vehicle;
        if($r->filled('vehicle_id')&&preg_match('~^api/(documents|vehicle-locations|fleet-access)(?:/|$)~',$path))$vehicle=Vehicle::findOrFail($r->integer('vehicle_id'));
        if(preg_match('~^api/vehicle-locations/(\d+)$~',$path,$m))$vehicle=Vehicle::findOrFail(DB::table('vehicle_locations')->where('id',$m[1])->value('vehicle_id'));
        if(preg_match('~^api/fleet-access/(\d+)$~',$path,$m))$vehicle=Vehicle::findOrFail(DB::table('fleet_access')->where('id',$m[1])->value('vehicle_id'));
        foreach(array_filter([$originalVehicle,$vehicle]) as $vehicle) {
            abort_unless($access->visibleVehicles($user)->whereKey($vehicle->id)->exists(),403);
            if(!$r->isMethod('GET')) {
                $ability=str_starts_with($path,'api/fleet-access')?'permissions':(str_starts_with($path,'api/vehicle-locations')?'handover':'service');
                $access->vehicle($user,$vehicle,$ability);
            }
        }
        return $next($r);
    }
}
