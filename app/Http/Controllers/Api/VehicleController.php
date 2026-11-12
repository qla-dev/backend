<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use App\Services\FleetOperations;

class VehicleController extends CrudController
{
    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'owner_user_id' => ['nullable', 'integer', 'exists:users,id'], 'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'registration_number' => [$p, 'string', 'max:80'], 'vin' => ['nullable', 'string', 'max:80'], 'transport_type' => ['sometimes', 'in:road,air,sea'], 'vehicle_type' => [$p, 'string', 'max:100'], 'make' => ['nullable', 'string', 'max:100'], 'model' => ['nullable', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1900,2100'], 'capacity_kg' => ['nullable', 'numeric', 'min:0'], 'capacity_m3' => ['nullable', 'numeric', 'min:0'], 'ownership_type' => ['sometimes', 'in:owned,financed,leasing,rented,other'], 'status' => ['sometimes', 'string', 'max:50'], 'features' => ['nullable', 'array']];
    }

    protected function relations(): array
    {
        // Only the newest fix: a tracked truck writes one a second, so the full trail runs to tens of
        // thousands of rows per vehicle - far too much to ship with every fleet listing.
        return ['company', 'owner', 'assignedDriver', 'permittedUsers', 'latestLocation', 'documents'];
    }

    /** A load is on the truck, and being tracked, while it is in delivery. */
    private const CARRYING_STATUSES = ['in_delivery'];

    protected function searchColumns(): array
    {
        return ['registration_number', 'vin', 'make', 'model'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if (!$request->boolean('include_archived')) $query->whereNotIn('status',['sold','returned','stolen']);
        $user = $request->user();
        if (! $user) {
            return;
        }

        if ($user->role?->name === 'driver') {
            $query->where(function (Builder $visibility) use ($user): void {
                $visibility
                    ->where('owner_user_id', $user->id)
                    // A truck assigned to the driver is one they may report positions for, so they must see it.
                    ->orWhere('assigned_driver_user_id', $user->id)
                    ->orWhereHas('permittedUsers', function (Builder $permittedUsers) use ($user): void {
                        $permittedUsers
                            ->where('users.id', $user->id)
                            ->where('fleet_access.can_view', true);
                    });
            });

            return;
        }

        if (in_array($user->role?->name, ['company', 'manager', 'dispatcher', 'customs_officer'], true)) {
            $companyIds = $user->companies()->pluck('companies.id');
            $carryingOurLoad = fn ($loads) => $loads->whereIn('company_id', $companyIds)->whereIn('status', self::CARRYING_STATUSES);
            // Their own fleet, plus any partner's truck - an independent driver's, say - that is carrying
            // one of their loads right now, so the shipment can be followed on the vehicle map too.
            $query->where(fn (Builder $visible) => $visible
                ->whereIn('company_id', $companyIds)
                ->orWhereHas('loads', $carryingOurLoad))
                ->withExists(['loads as carrying_your_load' => $carryingOurLoad]);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $user = $request->user();
        $role = $user?->role?->name;

        if ($user && ! $user->isSuperAdminOrMaster()) {
            // Ownership comes from the authenticated creator, never from an optional frontend prop.
            $data['owner_user_id'] = $user->id;
        }

        if ($user && in_array($role, ['company', 'manager', 'dispatcher', 'customs_officer'], true)) {
            $companyIds = $user->companies()->pluck('companies.id');
            $requestedCompanyId = isset($data['company_id']) ? (int) $data['company_id'] : null;
            $companyId = $requestedCompanyId && $companyIds->contains($requestedCompanyId)
                ? $requestedCompanyId
                : $companyIds->first();

            if (! $companyId) {
                throw ValidationException::withMessages([
                    'company_id' => ['Your account is not connected to a company.'],
                ]);
            }

            $data['company_id'] = $companyId;
        }

        if ($user && $role === 'driver') {
            $data['assigned_driver_user_id'] = $user->id;
            $data['company_id'] = $user->driver?->primary_company_id
                ?? $user->companies()->value('companies.id')
                ?? null;
        }

        abort_if(in_array($data['status'] ?? '', ['returned','sold','stolen'], true),422,'Register an active vehicle, then archive it through the fleet workflow.');
        $vehicle = DB::transaction(function()use($data,$user){
            if(!empty($data['company_id'])) {
                app(FleetOperations::class)->authorize($user,(int)$data['company_id'],false);
                DB::table('companies')->where('id',$data['company_id'])->lockForUpdate()->firstOrFail();
                if(!empty($data['assigned_driver_user_id'])) {
                    app(FleetOperations::class)->driver((int)$data['company_id'],(int)$data['assigned_driver_user_id']);
                    abort_if(Vehicle::where('company_id',$data['company_id'])->where('assigned_driver_user_id',$data['assigned_driver_user_id'])->exists(),422,'Driver already has another vehicle. Use a handover first.');
                }
            }
            $v=Vehicle::query()->create($data);
            if($v->company_id&&$v->assigned_driver_user_id) DB::table('fleet_assignments')->insert(['company_id'=>$v->company_id,'vehicle_id'=>$v->id,'driver_id'=>$v->assigned_driver_user_id,'started_at'=>now(),'created_at'=>now(),'updated_at'=>now()]);
            return $v;
        });
        $vehicle->load($this->relations());

        return $this->success(
            (new EntityResource($vehicle))->resolve($request),
            'Resource created successfully.',
            status: 201,
        );
    }

    public function update(Request $request,int $id): JsonResponse
    {
        $v=Vehicle::findOrFail($id);$user=$request->user();
        if($v->company_id && !($user->role?->name==='driver'&&(int)$v->owner_user_id===(int)$user->id))app(FleetOperations::class)->authorize($user,(int)$v->company_id);
        else abort_unless($user->isSuperAdminOrMaster()||(int)$v->owner_user_id===(int)$user->id,403);
        $d=$request->validate($this->rules(true));
        abort_if(in_array($v->status,['sold','returned'],true),422,'Archived vehicle details are read-only.');
        abort_if(array_key_exists('company_id',$d)&&(int)$d['company_id']!==(int)$v->company_id,422,'Company ownership cannot be changed through vehicle editing.');
        abort_if(array_key_exists('assigned_driver_user_id',$d)&&(int)$d['assigned_driver_user_id']!==(int)$v->assigned_driver_user_id,422,'Use a vehicle handover to change the assigned driver.');
        abort_if(isset($d['status'])&&$d['status']!==$v->status,422,'Use fleet status management or a handover to change vehicle status.');
        unset($d['owner_user_id']);
        $v->update($d);$v->load($this->relations());
        return $this->success((new EntityResource($v))->resolve($request),'Vehicle updated.');
    }

    public function destroy(Request $request,int $id): JsonResponse
    {
        $v=Vehicle::findOrFail($id);
        if($v->company_id)app(FleetOperations::class)->authorize($request->user(),(int)$v->company_id);
        else abort_unless($request->user()->isSuperAdminOrMaster()||(int)$v->owner_user_id===(int)$request->user()->id,403);
        abort(422,'Archive the vehicle using a sold status or rental return. Vehicle history is retained.');
    }
}
