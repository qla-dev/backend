<?php

namespace App\Services;

use App\Models\User;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FleetOperations
{
    public const ACTIVE_STATUSES = ['active','available','idle','in_use','depot'];
    public const STATUSES = ['active','available','idle','maintenance','in_use','depot','for_sale','sold','returned','stolen'];

    public function assertDispatchable(?int $vehicle): void
    {
        if(!$vehicle)return;
        $v=Vehicle::whereKey($vehicle)->lockForUpdate()->firstOrFail();
        abort_if($v->dispatch_blocked||!in_array($v->status,self::ACTIVE_STATUSES,true),422,'Vehicle is archived, in workshop, or blocked for dispatch.');
    }

    public function authorize(User $user, int $company, bool $write = true): string
    {
        $role = $this->role($user,$company);
        abort_unless(in_array($role, $write ? ['superadmin','master','company','manager','dispatcher'] : ['superadmin','master','company','manager','dispatcher','driver'], true), 403);
        return $role;
    }

    public function role(User $user,int $company): ?string
    {
        return app(CompanyTeamAccess::class)->role($user,$company) ?? ((int)$user->driver?->primary_company_id===$company?'driver':null);
    }

    public function driver(int $company, int $driver): void
    {
        $user = User::find($driver);
        $role = $user ? app(CompanyTeamAccess::class)->role($user, $company) : null;
        $profile = $user?->driver;
        abort_unless($user && ($role === 'driver' || ($profile && (int)$profile->primary_company_id === $company)), 422, 'Select an active driver from this company.');
    }

    public function event(int $company, int $actor, string $type, int $id, string $action, array $details = []): void
    {
        DB::table('fleet_events')->insert(['company_id'=>$company, 'actor_id'=>$actor, 'entity_type'=>$type, 'entity_id'=>$id,
            'action'=>$action, 'details'=>json_encode($details), 'created_at'=>now(), 'updated_at'=>now()]);
    }

    /** Caller holds the vehicle lock. Transfer is immediate; review never silently changes custody. */
    public function handover(Vehicle $v, int $actor, array $data): int
    {
        $at = now();
        $fromDriver=$v->assigned_driver_user_id;
        if (in_array($v->status, ['sold','returned','stolen'], true)) abort(422, 'This vehicle is archived or unavailable.');
        if ($data['destination'] === 'returned' && $v->ownership_type !== 'rented') abort(422, 'Only rented vehicles can be returned to the lessor.');
        $to = $data['destination'] === 'driver' ? (int)($data['to_driver_id'] ?? 0) : null;
        if ($to) {
            $this->driver((int)$v->company_id, $to);
            abort_if($v->dispatch_blocked || !in_array($v->status, self::ACTIVE_STATUSES, true), 422, 'Vehicle is not available for dispatch.');
            abort_if((int)$v->assigned_driver_user_id === $to, 422, 'Driver already has this vehicle.');
            abort_if(Vehicle::where('company_id',$v->company_id)->where('assigned_driver_user_id',$to)->where('id','!=',$v->id)->exists(),422,'Driver already has another vehicle. Return it first.');
        }
        $previous = DB::table('fleet_handovers')->where('vehicle_id',$v->id)->max('mileage_km');
        if ($previous !== null && $data['mileage_km'] < $previous) throw ValidationException::withMessages(['mileage_km'=>['Mileage cannot decrease.']]);
        $id = DB::table('fleet_handovers')->insertGetId([
            'company_id'=>$v->company_id, 'vehicle_id'=>$v->id, 'load_id'=>$data['load_id'] ?? null,
            'inspection_id'=>$data['inspection_id'] ?? null, 'from_driver_id'=>$v->assigned_driver_user_id,
            'to_driver_id'=>$to, 'recorded_by'=>$actor, 'destination'=>$data['destination'],
            'depot_name'=>$data['depot_name'] ?? $v->depot_name, 'plate'=>$v->registration_number,
            'mileage_km'=>$data['mileage_km'], 'fuel_level_percent'=>$data['fuel_level_percent'],
            'has_damage'=>$data['has_damage'] ?? false, 'notes'=>$data['notes'] ?? null,
            'effective_at'=>$at, 'created_at'=>$at, 'updated_at'=>$at,
        ]);
        DB::table('fleet_assignments')->where('vehicle_id',$v->id)->whereNull('ended_at')->update(['ended_at'=>$at,'updated_at'=>$at]);
        if ($to) DB::table('fleet_assignments')->insert(['company_id'=>$v->company_id,'vehicle_id'=>$v->id,'driver_id'=>$to,'handover_id'=>$id,'started_at'=>$at,'created_at'=>$at,'updated_at'=>$at]);
        $v->update(['assigned_driver_user_id'=>$to,'depot_name'=>$data['depot_name'] ?? $v->depot_name,
            'status'=>match($data['destination']) {'driver'=>'in_use','workshop'=>'maintenance','returned'=>'returned',default=>'depot'}]);
        $this->event((int)$v->company_id,$actor,'handover',$id,'created',['from_driver_id'=>$fromDriver,'to_driver_id'=>$to]);
        return $id;
    }

    public function damage(int $company, int $actor, int $vehicle, ?int $handover, array $data): int
    {
        $id = DB::table('fleet_damages')->insertGetId(['company_id'=>$company,'vehicle_id'=>$vehicle,'handover_id'=>$handover,
            'recorded_by'=>$actor,'kind'=>$data['kind'] ?? 'internal','description'=>$data['description'],
            'details'=>json_encode($data['details'] ?? []),'created_at'=>now(),'updated_at'=>now()]);
        DB::table('fleet_notifications')->insert(['company_id'=>$company,'damage_id'=>$id,'created_at'=>now(),'updated_at'=>now()]);
        if (!empty($data['block_vehicle'])) Vehicle::whereKey($vehicle)->update(['dispatch_blocked'=>true]);
        $this->event($company,$actor,'damage',$id,'reported');
        return $id;
    }

    public function lockTour(object $tour, int $actor): void
    {
        abort_unless($tour->status === 'open' && $tour->driver_id && $tour->vehicle_id,422,'Assign a driver and vehicle to an open tour first.');
        $v = Vehicle::whereKey($tour->vehicle_id)->lockForUpdate()->firstOrFail();
        abort_unless((int)$v->company_id === (int)$tour->company_id,403);
        $this->driver((int)$tour->company_id,(int)$tour->driver_id);
        $day = Carbon::parse($tour->service_date);
        abort_if($day->isFuture() && !$day->isToday(),422,'Future tours cannot be locked before the service day.');
        // End-of-day closure happens AFTER the return handover. Retain that day's actual custody,
        // rather than demanding that the driver still be sitting in the returned vehicle.
        $assignment = DB::table('fleet_assignments')->where('vehicle_id',$v->id)->where('driver_id',$tour->driver_id)
            ->where('started_at','<=',$day->copy()->endOfDay())->where(fn($q)=>$q->whereNull('ended_at')->orWhere('ended_at','>',$day->copy()->startOfDay()))->orderByDesc('started_at')->orderByDesc('id')->first();
        abort_unless($assignment,422,'No matching vehicle handover / valid driver assignment for this tour.');
        $plate=$this->assignmentEvidence($assignment,$v);
        DB::table('fleet_tours')->where('id',$tour->id)->update(['status'=>'locked','assignment_id'=>$assignment->id,'plate'=>$plate,'locked_by'=>$actor,'locked_at'=>now(),'updated_at'=>now()]);
        $this->event((int)$tour->company_id,$actor,'tour',$tour->id,'locked',['assignment_id'=>$assignment->id,'plate'=>$plate]);
    }

    public function assignmentEvidence(object $assignment,Vehicle $v): string
    {
        if($assignment->handover_id)abort_unless(DB::table('fleet_handovers')->where('id',$assignment->handover_id)->where('review_status','confirmed')->exists(),422,'Review the vehicle handover before locking or completing this tour.');
        if($assignment->ended_at){
            $return=DB::table('fleet_handovers')->where('vehicle_id',$v->id)->where('from_driver_id',$assignment->driver_id)->where('effective_at',$assignment->ended_at)->orderByDesc('id')->first();
            abort_unless($return&&$return->review_status==='confirmed',422,'Review the closing handover before completing this driver’s tour.');
            return $return->plate;
        }
        abort_unless((int)$v->assigned_driver_user_id===(int)$assignment->driver_id,422,'Current driver does not match the vehicle assignment.');
        abort_if($v->dispatch_blocked||!in_array($v->status,self::ACTIVE_STATUSES,true),422,'Vehicle is not available for dispatch.');
        return $v->registration_number;
    }
}
