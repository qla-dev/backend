<?php

namespace App\Http\Controllers\Api;

use App\Models\Role;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dock ledger: every inbound and outbound movement booked against a warehouse.
 *
 * WarehouseController::overview already aggregates today's rows into the dashboard's schedule
 * panel, but that is a summary of one day. This is the same ledger as a list a warehouse works
 * from - filtered by facility, direction, status and date - which is what "My docks" is to a
 * warehouse account and what "My cargo" is to a carrier.
 *
 * Scoping mirrors the overview exactly: an account sees the movements of the facilities it owns,
 * while master/superadmin see the whole network.
 */
class WarehouseMovementController extends CrudController
{
    protected function modelClass(): string
    {
        return WarehouseMovement::class;
    }

    protected function relations(): array
    {
        return ['warehouse', 'freightLoad'];
    }

    protected function searchColumns(): array
    {
        return ['customer_name', 'description', 'dock_number', 'storage_type'];
    }

    protected function rules(bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return [
            'warehouse_id' => [$required, 'integer', 'exists:warehouses,id'],
            'load_id' => ['nullable', 'integer', 'exists:loads,id'],
            'direction' => [$required, 'string', 'in:inbound,outbound'],
            'status' => ['sometimes', 'string', 'in:booked,scheduled,in_progress,completed,cancelled'],
            'scheduled_at' => [$required, 'date'],
            'completed_at' => ['nullable', 'date'],
            'dock_number' => ['nullable', 'string', 'max:20'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'storage_type' => ['nullable', 'string', 'max:100'],
            'pallets' => ['nullable', 'integer', 'min:0'],
            'cbm' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'rate' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        $user = $request->user();
        // Resolved from role_id rather than a preloaded relation, the same way the overview does
        // it, so a warehouse account is never accidentally handed the whole network.
        $isNetworkView = $user && Role::query()
            ->whereKey($user->role_id)
            ->whereIn('name', Role::PROTECTED_NAMES)
            ->exists();

        if (! $isNetworkView) {
            $ownerIds = $user->companies()->pluck('companies.owner_user_id')->push($user->id)->unique();
            $query->whereIn('warehouse_id', Warehouse::query()->whereIn('user_id', $ownerIds)->pluck('id'));
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->integer('warehouse_id'));
        }

        if ($request->filled('direction')) {
            $query->where('direction', $request->string('direction'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        // A dock day is what the schedule is read by, so both ends are inclusive dates rather than
        // timestamps: ?date_from=2026-09-02 covers everything booked that day.
        if ($request->filled('date_from')) {
            $query->whereDate('scheduled_at', '>=', $request->date('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('scheduled_at', '<=', $request->date('date_to'));
        }
    }

    protected function applyOrdering(Builder $query, Request $request): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $isNetworkView = $user?->isSuperAdminOrMaster() ?? false;
        if (! $isNetworkView) {
            $ownerIds = $user->companies()->pluck('companies.owner_user_id')->push($user->id)->unique();
            abort_unless(
                Warehouse::query()->whereKey($request->integer('warehouse_id'))->whereIn('user_id', $ownerIds)->exists(),
                403,
                'You cannot create movements for this warehouse.',
            );
        }

        return parent::store($request);
    }
}
