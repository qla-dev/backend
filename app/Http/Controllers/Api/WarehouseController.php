<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Company;
use App\Models\Load;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class WarehouseController extends CrudController
{
    protected function configureQuery(Builder $query): void
    {
        $query->withAvg('reviews as average_rating', 'rating')->withCount('reviews');
    }

    protected function modelClass(): string
    {
        return Warehouse::class;
    }

    protected function rules(bool $updating = false): array
    {
        $p = $updating ? 'sometimes' : 'required';

        return [
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'name' => [$p, 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'total_capacity_pallets' => ['nullable', 'integer', 'min:0'],
            'storage_types' => ['nullable', 'array'],
            'certifications' => ['nullable', 'array'],
            'plan' => ['sometimes', 'string', 'max:50'],
            'status' => ['sometimes', 'string', 'in:pending,verified,suspended,active,inactive,under maintenance'],
            'verified_at' => ['nullable', 'date'],
            'code' => ['nullable', 'string', 'max:60'],
            'warehouse_type' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:2000'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'state_province' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['nullable', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:50'],
            'contact_alternate_phone' => ['nullable', 'string', 'max:50'],
            'department' => ['nullable', 'string', 'max:80'],
            'preferred_contact_method' => ['nullable', 'string', 'max:20'],
            'manager_name' => ['nullable', 'string', 'max:255'],
            'manager_email' => ['nullable', 'email', 'max:255'],
            'manager_phone' => ['nullable', 'string', 'max:50'],
            'total_capacity_cbm' => ['nullable', 'integer', 'min:0'],
            'storage_area_sqm' => ['nullable', 'integer', 'min:0'],
            'dock_doors' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'operational_notes' => ['nullable', 'string', 'max:2000'],
            'documents_notes' => ['nullable', 'string', 'max:2000'],
            'utilization_thresholds' => ['nullable', 'array'],
            'storage_config' => ['nullable', 'array'],
            'temperature_zones' => ['nullable', 'array'],
            'inventory_settings' => ['nullable', 'array'],
            'equipment' => ['nullable', 'array'],
            'handling_capabilities' => ['nullable', 'array'],
            'operations' => ['nullable', 'array'],
            'capabilities' => ['nullable', 'array'],
            'technology' => ['nullable', 'array'],
            'compliance' => ['nullable', 'array'],
            'standards' => ['nullable', 'array'],
            'documents' => ['nullable', 'array'],
        ];
    }

    protected function relations(): array
    {
        return ['owner'];
    }

    protected function searchColumns(): array
    {
        return ['name', 'address', 'city', 'country_code'];
    }

    protected function applyOrdering(Builder $query, Request $request): void
    {
        $query->orderBy('name')->orderBy('id');
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['user_id'] = $request->user()->id;

        if (! $request->user()->isSuperAdminOrMaster()) {
            unset($data['verified_at']);
            $data['status'] = 'pending';
        }

        $warehouse = Warehouse::query()->create($data);

        return $this->success((new EntityResource($warehouse))->resolve($request), 'Warehouse created successfully.', status: 201);
    }

    // Owners may maintain their own facility, including its publication status. verified_at is
    // derived here so every quick-status control leaves the status and timestamp consistent.
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate($this->rules(true));

        $warehouse = Warehouse::query()->findOrFail($id);
        $this->authorizeWarehouse($request, $warehouse);
        unset($data['user_id'], $data['verified_at']);
        if (array_key_exists('status', $data)) {
            $data['verified_at'] = $data['status'] === 'verified' ? now() : null;
        }
        $warehouse->update($data);
        $warehouse->load($this->relations());

        return $this->success((new EntityResource($warehouse))->resolve($request), 'Warehouse updated successfully.');
    }

    // Creates the warehouse company and its owner login in one step, mirroring how a logistics
    // company is onboarded - the admin console never has to create the user separately.
    public function onboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'country_code' => ['required', 'string', 'size:2'],
            'city' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:100'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'total_capacity_pallets' => ['nullable', 'integer', 'min:0'],
            'storage_types' => ['nullable', 'array'],
            'plan' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'owner_username' => ['required', 'string', 'max:80', 'unique:users,username'],
            'owner_password' => ['required', 'string', 'min:8'],
            'owner_phone' => ['nullable', 'string', 'max:50'],
        ]);

        $company = DB::transaction(function () use ($data, $request) {
            $role = Role::query()->where('name', 'company')->firstOrFail();
            $owner = User::query()->create([
                'role_id' => $role->id, 'name' => $data['owner_name'], 'email' => $data['owner_email'],
                'username' => $data['owner_username'], 'password' => $data['owner_password'],
                'phone' => $data['owner_phone'] ?? null, 'language' => 'bs',
                'country_code' => strtoupper($data['country_code']), 'is_active' => true, 'email_verified_at' => now(),
            ]);

            $baseSlug = Str::slug($data['company_name']) ?: 'warehouse-company';
            $slug = $baseSlug;
            $suffix = 2;
            while (Company::query()->where('slug', $slug)->exists()) $slug = $baseSlug.'-'.$suffix++;

            $company = Company::query()->create([
                'owner_user_id' => $owner->id, 'name' => $data['company_name'], 'slug' => $slug,
                'email' => $data['company_email'] ?? null, 'phone' => $data['company_phone'] ?? null,
                'tax_number' => $data['tax_number'] ?? null, 'registration_number' => $data['registration_number'] ?? null,
                'country_code' => strtoupper($data['country_code']), 'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null, 'warehouse_first' => true,
                'plan' => $data['plan'] ?? 'starter', 'status' => $data['status'] ?? 'pending',
            ]);
            $company->users()->attach($owner->id, [
                'status' => 'active',
                'invited_by_user_id' => $request->user()->id, 'joined_at' => now(),
            ]);
            Warehouse::query()->create([
                'user_id' => $owner->id, 'name' => $data['company_name'],
                'email' => $data['company_email'] ?? null, 'phone' => $data['company_phone'] ?? null,
                'tax_number' => $data['tax_number'] ?? null, 'registration_number' => $data['registration_number'] ?? null,
                'country_code' => strtoupper($data['country_code']), 'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'total_capacity_pallets' => (int) ($data['total_capacity_pallets'] ?? 0),
                'storage_types' => $data['storage_types'] ?? null,
                'plan' => $data['plan'] ?? 'starter', 'status' => $data['status'] ?? 'pending',
            ]);

            return $company->load(['owner', 'users', 'warehouses']);
        });

        return $this->success((new EntityResource($company))->resolve($request), 'Warehouse company and owner account created.', status: 201);
    }

    // Everything the "Moj Warehouse" dashboard renders, aggregated server-side from a single
    // inbound/outbound ledger (warehouse_movements) so the frontend never computes business figures
    // itself - it only fetches and displays this one payload. An account can operate several
    // facilities: the figures below cover all of them at once unless ?warehouse_id= narrows the scope.
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        // Resolve the protected role directly from role_id. This endpoint must never accidentally
        // scope a master/superadmin to warehouses they personally own because the relation was not
        // preloaded on the Sanctum user instance.
        $isNetworkView = $user && Role::query()
            ->whereKey($user->role_id)
            ->whereIn('name', Role::PROTECTED_NAMES)
            ->exists();

        $warehouses = Warehouse::query()
            // Admin/master use the same operations dashboard as warehouse companies, but across
            // the complete network. A warehouse account remains strictly scoped to its own rows.
            ->when(! $isNetworkView, fn (Builder $query) => $query->where('user_id', $user->id))
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($warehouses->isEmpty()) {
            return $this->success([
                'warehouse' => null,
                'warehouses' => [],
                'selected_warehouse_id' => null,
                'stats' => [],
                'dock_schedule' => [],
                'inventory_summary' => [],
                'recent_arrivals' => [],
                'top_customers' => [],
            ], 'No warehouse found for this account.');
        }

        // An id the account does not own falls back to the combined view rather than erroring - the
        // dashboard treats "all facilities" as the default scope.
        $selected = $warehouses->firstWhere('id', (int) $request->query('warehouse_id', 0));
        $allIds = $warehouses->pluck('id')->all();
        $scopeIds = $selected ? [$selected->id] : $allIds;

        $net = "SUM(CASE WHEN direction = 'inbound' THEN pallets ELSE -pallets END)";
        $completed = WarehouseMovement::query()->whereIn('warehouse_id', $scopeIds)->where('status', 'completed');

        $netByCustomer = (clone $completed)
            ->selectRaw("customer_name, {$net} as net_pallets")
            ->groupBy('customer_name')
            ->havingRaw("{$net} > 0")
            ->orderByDesc('net_pallets')
            ->get();

        $netByStorageType = (clone $completed)
            ->selectRaw("storage_type, {$net} as net_pallets")
            ->groupBy('storage_type')
            ->havingRaw("{$net} > 0")
            ->orderByDesc('net_pallets')
            ->get();

        // Per-facility occupancy is computed over every facility, not just the selected scope, so the
        // facility list and its chart stay populated while a single warehouse is being inspected.
        $netByWarehouse = WarehouseMovement::query()
            ->whereIn('warehouse_id', $allIds)
            ->where('status', 'completed')
            ->selectRaw("warehouse_id, {$net} as net_pallets")
            ->groupBy('warehouse_id')
            ->pluck('net_pallets', 'warehouse_id');

        $facilities = $warehouses->map(function (Warehouse $warehouse) use ($netByWarehouse): array {
            $capacity = max(0, (int) $warehouse->total_capacity_pallets);
            $occupied = max(0, (int) ($netByWarehouse[$warehouse->id] ?? 0));

            return [
                'id' => $warehouse->id,
                'name' => $warehouse->name,
                'city' => $warehouse->city,
                'country_code' => $warehouse->country_code,
                'status' => $warehouse->status,
                'storage_types' => $warehouse->storage_types,
                'total_capacity_pallets' => $capacity,
                'occupied_pallets' => $occupied,
                'available_pallets' => max(0, $capacity - $occupied),
                'occupancy_percent' => $capacity > 0 ? min(100, round(($occupied / $capacity) * 100, 1)) : 0.0,
            ];
        });

        $inScope = $facilities->whereIn('id', $scopeIds);
        $occupiedPallets = (int) $inScope->sum('occupied_pallets');
        $totalCapacity = (int) $inScope->sum('total_capacity_pallets');
        $occupancyPercent = $totalCapacity > 0 ? min(100, round(($occupiedPallets / $totalCapacity) * 100, 1)) : 0.0;

        $names = $warehouses->pluck('name', 'id');
        $today = Carbon::today();
        $todaysMovements = WarehouseMovement::query()
            ->whereIn('warehouse_id', $scopeIds)
            ->whereDate('scheduled_at', $today)
            ->orderBy('scheduled_at')
            ->get()
            ->map(fn (WarehouseMovement $movement) => $movement->toArray() + ['warehouse_name' => $names[$movement->warehouse_id] ?? null]);

        $recentArrivals = (clone $completed)
            ->where('direction', 'inbound')
            ->orderByDesc('completed_at')
            ->limit(5)
            ->get()
            ->map(fn (WarehouseMovement $movement) => $movement->toArray() + ['warehouse_name' => $names[$movement->warehouse_id] ?? null]);

        $monthStart = Carbon::now()->startOfMonth();
        $storageRevenue = (clone $completed)
            ->where('direction', 'inbound')
            ->where('completed_at', '>=', $monthStart)
            ->sum('rate');
        $totalRevenue = (clone $completed)->where('direction', 'inbound')->sum('rate');

        return $this->success([
            'warehouse' => $selected ?? $warehouses->first(),
            'warehouses' => $facilities->values(),
            'selected_warehouse_id' => $selected?->id,
            'stats' => [
                'warehouse_count' => $warehouses->count(),
                'scoped_warehouse_count' => count($scopeIds),
                'occupancy_percent' => $occupancyPercent,
                'occupied_pallets' => $occupiedPallets,
                'available_pallets' => max(0, $totalCapacity - $occupiedPallets),
                'total_capacity_pallets' => $totalCapacity,
                'inbound_today' => $todaysMovements->where('direction', 'inbound')->count(),
                'outbound_today' => $todaysMovements->where('direction', 'outbound')->count(),
                'storage_revenue' => round((float) $storageRevenue, 2),
                'total_revenue' => round((float) $totalRevenue, 2),
                'currency' => WarehouseMovement::query()->whereIn('warehouse_id', $scopeIds)->value('currency') ?? 'EUR',
            ],
            'dock_schedule' => $todaysMovements->values(),
            'inventory_summary' => $netByStorageType->values(),
            'recent_arrivals' => $recentArrivals->values(),
            'top_customers' => $netByCustomer->take(5)->values(),
        ], 'Warehouse overview retrieved successfully.');
    }

    // Everything the warehouse status screen shows for ONE facility: the full record, its
    // occupancy, and - the part the overview cannot answer - exactly which goods are sitting
    // inside right now. Stock is the net of the completed inbound/outbound ledger grouped per
    // load, so a consignment that has already shipped out drops off the list on its own.
    public function status(Request $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $this->authorizeWarehouse($request, $warehouse);

        $net = "SUM(CASE WHEN direction = 'inbound' THEN pallets ELSE -pallets END)";
        $netCbm = "SUM(CASE WHEN direction = 'inbound' THEN COALESCE(cbm, 0) ELSE -COALESCE(cbm, 0) END)";
        $netWeight = "SUM(CASE WHEN direction = 'inbound' THEN COALESCE(weight_kg, 0) ELSE -COALESCE(weight_kg, 0) END)";
        $completed = WarehouseMovement::query()->where('warehouse_id', $warehouse->id)->where('status', 'completed');

        $stock = (clone $completed)
            ->selectRaw("load_id, customer_name, storage_type, {$net} as pallets, {$netCbm} as cbm, {$netWeight} as weight_kg, MIN(completed_at) as stored_since, MAX(currency) as currency, SUM(CASE WHEN direction = 'inbound' THEN COALESCE(rate, 0) ELSE 0 END) as rate, MAX(description) as description")
            ->groupBy('load_id', 'customer_name', 'storage_type')
            ->havingRaw("{$net} > 0")
            ->orderByDesc('pallets')
            ->get();

        $loads = Load::query()
            ->whereIn('id', $stock->pluck('load_id')->filter()->unique()->all())
            ->get(['id', 'title', 'goods_type', 'cargo_type', 'storage_type', 'storage_start_date', 'storage_end_date', 'is_storage_ongoing', 'weight_kg', 'volume_m3', 'pallets', 'status', 'temperature_min', 'temperature_max'])
            ->keyBy('id');

        $capacity = max(0, (int) $warehouse->total_capacity_pallets);
        $occupied = max(0, (int) $stock->sum('pallets'));
        $today = Carbon::today();

        $movements = WarehouseMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->orderByDesc('scheduled_at')
            ->limit(25)
            ->get();

        return $this->success([
            'warehouse' => $warehouse,
            'occupancy' => [
                'total_capacity_pallets' => $capacity,
                'occupied_pallets' => $occupied,
                'available_pallets' => max(0, $capacity - $occupied),
                'occupancy_percent' => $capacity > 0 ? min(100, round(($occupied / $capacity) * 100, 1)) : 0.0,
            ],
            'stats' => [
                'stock_rows' => $stock->count(),
                'stored_weight_kg' => round((float) $stock->sum('weight_kg'), 2),
                'stored_cbm' => round((float) $stock->sum('cbm'), 2),
                'inbound_today' => (clone $completed)->where('direction', 'inbound')->whereDate('scheduled_at', $today)->count(),
                'outbound_today' => (clone $completed)->where('direction', 'outbound')->whereDate('scheduled_at', $today)->count(),
                'scheduled_pending' => WarehouseMovement::query()->where('warehouse_id', $warehouse->id)->where('status', 'scheduled')->count(),
                'storage_revenue' => round((float) (clone $completed)->where('direction', 'inbound')->sum('rate'), 2),
                'currency' => (clone $completed)->value('currency') ?? 'EUR',
            ],
            'by_storage_type' => (clone $completed)
                ->selectRaw("storage_type, {$net} as net_pallets")
                ->groupBy('storage_type')
                ->havingRaw("{$net} > 0")
                ->orderByDesc('net_pallets')
                ->get()
                ->values(),
            'stock' => $stock->map(fn (WarehouseMovement $row) => $row->toArray() + ['load' => $loads[$row->load_id] ?? null])->values(),
            'movements' => $movements->values(),
        ], 'Warehouse status retrieved successfully.');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::query()->findOrFail($id);
        $this->authorizeWarehouse($request, $warehouse);
        $warehouse->delete();

        return $this->success(null, 'Warehouse deleted successfully.');
    }

    private function authorizeWarehouse(Request $request, Warehouse $warehouse): void
    {
        if ($request->user()->isSuperAdminOrMaster()) return;

        abort_unless((int) $warehouse->user_id === (int) $request->user()->id, 403);
    }
}
