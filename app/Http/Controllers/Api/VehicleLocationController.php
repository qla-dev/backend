<?php

namespace App\Http\Controllers\Api;

use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VehicleLocationController extends CrudController
{
    /** One request may not carry more breadcrumbs than this. */
    private const BULK_LIMIT = 500;

    protected function modelClass(): string
    {
        return VehicleLocation::class;
    }

    protected function relations(): array
    {
        return ['vehicle'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['vehicle_id' => [$p, 'integer', 'exists:vehicles,id'], 'latitude' => [$p, 'numeric', 'between:-90,90'], 'longitude' => [$p, 'numeric', 'between:-180,180'], 'speed_kph' => ['nullable', 'numeric', 'min:0'], 'heading' => ['nullable', 'numeric', 'between:0,360'], 'recorded_at' => [$p, 'date']];
    }

    /**
     * Records a run of positions in one request.
     *
     * A driver's phone samples its position once a second while a shipment is being tracked, and it
     * has to keep recording through tunnels and dead zones - so it queues locally and hands over
     * whatever has accumulated. One row per request would make a backlog after an outage a burst of
     * hundreds of round trips; this takes the run as it comes. Duplicate breadcrumbs (a retry after
     * an uncertain response) are ignored rather than doubled, keyed on vehicle + instant.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'positions' => ['required', 'array', 'min:1', 'max:'.self::BULK_LIMIT],
            'positions.*.vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'positions.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'positions.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'positions.*.speed_kph' => ['nullable', 'numeric', 'min:0'],
            'positions.*.heading' => ['nullable', 'numeric', 'between:0,360'],
            'positions.*.recorded_at' => ['required', 'date'],
        ]);

        $user = $request->user();
        $vehicleIds = collect($data['positions'])->pluck('vehicle_id')->unique();
        // A position may only be written for a vehicle this account actually has access to.
        $permitted = Vehicle::query()
            ->whereKey($vehicleIds)
            ->when(! $user->isSuperAdminOrMaster(), function ($query) use ($user): void {
                $companyIds = $user->companies()->pluck('companies.id');
                $query->where(function ($scope) use ($user, $companyIds): void {
                    $scope->where('owner_user_id', $user->id)
                        ->orWhere('assigned_driver_user_id', $user->id)
                        ->orWhereHas('permittedUsers', fn ($users) => $users->whereKey($user->id));
                    if ($companyIds->isNotEmpty()) {
                        $scope->orWhereIn('company_id', $companyIds);
                    }
                });
            })
            ->pluck('id');

        abort_if($permitted->count() !== $vehicleIds->count(), 403, 'You cannot record positions for this vehicle.');

        $now = now();
        $rows = collect($data['positions'])->map(fn (array $position): array => [
            'vehicle_id' => (int) $position['vehicle_id'],
            'latitude' => $position['latitude'],
            'longitude' => $position['longitude'],
            'speed_kph' => $position['speed_kph'] ?? null,
            'heading' => $position['heading'] ?? null,
            'recorded_at' => Carbon::parse($position['recorded_at']),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        // insertOrIgnore keeps a retried batch idempotent where a unique index covers
        // (vehicle_id, recorded_at), and behaves as a plain insert where it does not.
        DB::table('vehicle_locations')->insertOrIgnore($rows);

        return $this->success(
            ['accepted' => count($rows)],
            'Positions recorded successfully.',
            status: 201
        );
    }
}
