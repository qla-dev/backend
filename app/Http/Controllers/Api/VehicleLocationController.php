<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        return ['vehicle', 'user:id,name'];
    }

    /**
     * A vehicle's breadcrumbs are visible to the people who may see the vehicle itself. The index
     * inherited no filter at all, so `?vehicle_id=` was ignored and every authenticated account
     * could page through every vehicle's position history on the platform - another company's
     * trucks included. The write path (bulkStore) has always checked this; the read path now does.
     */
    protected function applyFilters(Builder $query, Request $request): void
    {
        $request->validate(['vehicle_id' => ['sometimes', 'integer']]);
        if ($request->filled('vehicle_id')) {
            $query->where('vehicle_id', $request->integer('vehicle_id'));
        }

        $user = $request->user();
        if (! $user || $user->isSuperAdminOrMaster()) {
            return;
        }
        $query->whereIn('vehicle_id', $this->permittedVehicleIds($user));
    }

    /** The vehicles this account owns, drives, has fleet access to, or whose company it belongs to. */
    private function permittedVehicleIds(User $user): Collection
    {
        $companyIds = $user->companies()->pluck('companies.id');

        return Vehicle::query()
            ->where(function (Builder $scope) use ($user, $companyIds): void {
                $scope->where('owner_user_id', $user->id)
                    ->orWhere('assigned_driver_user_id', $user->id)
                    ->orWhereHas('permittedUsers', fn (Builder $users) => $users->whereKey($user->id));
                if ($companyIds->isNotEmpty()) {
                    $scope->orWhereIn('company_id', $companyIds);
                }
            })
            ->pluck('id');
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['vehicle_id' => [$p, 'integer', 'exists:vehicles,id'], 'latitude' => [$p, 'numeric', 'between:-90,90'], 'longitude' => [$p, 'numeric', 'between:-180,180'], 'speed_kph' => ['nullable', 'numeric', 'min:0'], 'heading' => ['nullable', 'numeric', 'between:0,360'], 'recorded_at' => [$p, 'date']];
    }

    /** The reporter is always the caller, never something the client can claim. */
    public function store(Request $request): JsonResponse
    {
        $record = VehicleLocation::query()->create([...$request->validate($this->rules()), 'user_id' => $request->user()?->id]);
        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource created successfully.', status: 201);
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
            'user_id' => $user->id,
            'latitude' => $position['latitude'],
            'longitude' => $position['longitude'],
            'speed_kph' => $position['speed_kph'] ?? null,
            'heading' => $position['heading'] ?? null,
            // Written through the query builder, so no cast converts it: the Eloquent convention is
            // that DATETIME columns hold app-timezone wall-clock time (that is how `recorded_at` is
            // read back and how `created_at` below is written). A phone sends UTC (`...Z`), and
            // inserting that instant's UTC wall clock unchanged had every position read back two
            // hours early in Europe/Sarajevo.
            'recorded_at' => Carbon::parse($position['recorded_at'])->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
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
