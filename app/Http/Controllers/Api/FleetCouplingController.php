<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Vehicle;
use App\Services\FleetDepotAccess;
use App\Services\FleetOperations;
use App\Services\VehicleRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FleetCouplingController extends Controller
{
    public function index(Request $r,int $company,int $id)
    {
        $v=Vehicle::where('company_id',$company)->findOrFail($id);$a=app(FleetDepotAccess::class);$a->vehicle($r->user(),$v);
        $visible=$a->query(Vehicle::where('company_id',$company),$r->user(),$company,'vehicle')->pluck('id');
        $rows=DB::table('fleet_vehicle_couplings as c')->where('c.company_id',$company)->where(fn($q)=>$q->where('c.towing_vehicle_id',$id)->orWhere('c.trailer_vehicle_id',$id))
            ->whereIn('c.towing_vehicle_id',$visible)->whereIn('c.trailer_vehicle_id',$visible)
            ->join('vehicles as motor','motor.id','=','c.towing_vehicle_id')->join('vehicles as trailer','trailer.id','=','c.trailer_vehicle_id')
            ->select('c.*','motor.registration_number as towing_plate','trailer.registration_number as trailer_plate')->orderByDesc('c.id')->paginate(25);
        return response()->json(['data'=>$rows]);
    }

    public function attach(Request $r,int $company,int $id)
    {
        $d=$r->validate(['trailer_id'=>['required','integer'],'position'=>['required','integer','between:1,2'],'reason'=>['required','string','max:1000']]);
        return DB::transaction(function()use($r,$company,$id,$d){
            app(FleetDepotAccess::class)->authorize($r->user(),$company,'dispatch');
            DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
            $assets=Vehicle::where('company_id',$company)->whereIn('id',[$id,$d['trailer_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $v=$assets->get($id);$trailer=$assets->get($d['trailer_id']);abort_unless($v&&$trailer&&$id!==(int)$d['trailer_id'],422,'Select two assets of this company.');
            foreach([$v,$trailer] as $asset){app(FleetDepotAccess::class)->vehicle($r->user(),$asset,'dispatch');abort_if($asset->dispatch_blocked||!in_array($asset->status,FleetOperations::ACTIVE_STATUSES,true),422,'An asset is unavailable or blocked.');}
            abort_if($v->depot_id&&$trailer->depot_id&&(int)$v->depot_id!==(int)$trailer->depot_id,422,'Move both assets to the same depot before coupling.');
            abort_unless(in_array($v->features['resource_type']??'',['rigid_truck','tractor_unit','cargo_van'],true)&&VehicleRegistry::isTrailer($trailer->features),422,'Select a classified motor vehicle and a registered trailer.');
            abort_if(($trailer->features['resource_type']??'')==='semi_trailer'&&($v->features['resource_type']??'')!=='tractor_unit',422,'A semi-trailer requires a tractor unit.');
            abort_if(DB::table('fleet_vehicle_couplings')->where('active_slot',$id.':'.$d['position'])->orWhere('active_trailer_id',$trailer->id)->exists(),422,'This slot or trailer is already coupled.');
            if($d['position']===2)abort_unless(DB::table('fleet_vehicle_couplings')->where('active_slot',$id.':1')->exists(),422,'Attach the first trailer before a second asset.');
            $coupling=DB::table('fleet_vehicle_couplings')->insertGetId(['company_id'=>$company,'towing_vehicle_id'=>$id,'trailer_vehicle_id'=>$trailer->id,'position'=>$d['position'],'active_slot'=>$id.':'.$d['position'],'active_trailer_id'=>$trailer->id,'attached_by'=>$r->user()->id,'started_at'=>now(),'attach_reason'=>$d['reason'],'created_at'=>now(),'updated_at'=>now()]);
            app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'trailer_attached',['coupling_id'=>$coupling,'trailer_id'=>$trailer->id,'reason'=>$d['reason']]);
            return response()->json(['data'=>['id'=>$coupling]],201);
        });
    }

    public function detach(Request $r,int $company,int $id,int $coupling)
    {
        $d=$r->validate(['reason'=>['required','string','max:1000']]);
        return DB::transaction(function()use($r,$company,$id,$coupling,$d){
            app(FleetDepotAccess::class)->authorize($r->user(),$company,'dispatch');
            DB::table('companies')->where('id',$company)->lockForUpdate()->firstOrFail();
            $row=DB::table('fleet_vehicle_couplings')->where('company_id',$company)->where('towing_vehicle_id',$id)->where('id',$coupling)->lockForUpdate()->firstOrFail();
            foreach([$row->towing_vehicle_id,$row->trailer_vehicle_id] as $asset)app(FleetDepotAccess::class)->vehicle($r->user(),Vehicle::where('company_id',$company)->findOrFail($asset),'dispatch');
            abort_if($row->ended_at,422,'This coupling has already ended.');
            if((int)$row->position===1)abort_if(DB::table('fleet_vehicle_couplings')->where('active_slot',$id.':2')->exists(),422,'Detach the second asset first.');
            DB::table('fleet_vehicle_couplings')->where('id',$coupling)->update(['ended_at'=>now(),'active_slot'=>null,'active_trailer_id'=>null,'detached_by'=>$r->user()->id,'detach_reason'=>$d['reason'],'updated_at'=>now()]);
            app(FleetOperations::class)->event($company,$r->user()->id,'vehicle',$id,'trailer_detached',['coupling_id'=>$coupling,'reason'=>$d['reason']]);
            return response()->json(['data'=>['id'=>$coupling]]);
        });
    }
}
