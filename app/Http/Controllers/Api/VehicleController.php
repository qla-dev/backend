<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

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
        if (! $user || $user->role?->name !== 'driver') {
            return;
        }

        $query->where(function (Builder $visibility) use ($user): void {
            $visibility
                ->where('owner_user_id', $user->id)
                ->orWhereHas('permittedUsers', function (Builder $permittedUsers) use ($user): void {
                    $permittedUsers
                        ->where('users.id', $user->id)
                        ->where('fleet_access.can_view', true);
                });
        });
    }
}
