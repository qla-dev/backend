<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VehicleController extends CrudController
{
    protected function modelClass(): string
    {
        return Vehicle::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return ['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'owner_user_id' => ['nullable', 'integer', 'exists:users,id'], 'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'registration_number' => [$p, 'string', 'max:80'], 'vin' => ['nullable', 'string', 'max:80'], 'transport_type' => ['sometimes', 'in:road,air,sea'], 'vehicle_type' => [$p, 'string', 'max:100'], 'make' => ['nullable', 'string', 'max:100'], 'model' => ['nullable', 'string', 'max:100'], 'year' => ['nullable', 'integer', 'between:1900,2100'], 'capacity_kg' => ['nullable', 'numeric', 'min:0'], 'capacity_m3' => ['nullable', 'numeric', 'min:0'], 'status' => ['sometimes', 'string', 'max:50'], 'features' => ['nullable', 'array']];
    }

    protected function relations(): array
    {
        return ['company', 'owner', 'assignedDriver', 'permittedUsers', 'locations', 'documents'];
    }

    protected function searchColumns(): array
    {
        return ['registration_number', 'vin', 'make', 'model'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $user = $request->user();
        if (! $user) {
            return;
        }

        if ($user->role?->name === 'driver') {
            $query->where(function (Builder $visibility) use ($user): void {
                $visibility
                    ->where('owner_user_id', $user->id)
                    ->orWhereHas('permittedUsers', function (Builder $permittedUsers) use ($user): void {
                        $permittedUsers
                            ->where('users.id', $user->id)
                            ->where('fleet_access.can_view', true);
                    });
            });

            return;
        }

        if (in_array($user->role?->name, ['company', 'manager', 'dispatcher', 'customs_officer'], true)) {
            $query->whereIn('company_id', $user->companies()->pluck('companies.id'));
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

        $vehicle = Vehicle::query()->create($data);
        $vehicle->load($this->relations());

        return $this->success(
            (new EntityResource($vehicle))->resolve($request),
            'Resource created successfully.',
            status: 201,
        );
    }
}
