<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class WarehouseController extends CrudController
{
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
            'status' => ['sometimes', 'string', 'max:50'],
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
        $data['user_id'] = $data['user_id'] ?? $request->user()->id;

        $warehouse = Warehouse::query()->create($data);

        return $this->success((new EntityResource($warehouse))->resolve($request), 'Warehouse created successfully.', status: 201);
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

        $warehouse = DB::transaction(function () use ($data) {
            $role = Role::query()->where('name', 'warehouse')->firstOrFail();
            $owner = User::query()->create([
                'role_id' => $role->id, 'name' => $data['owner_name'], 'email' => $data['owner_email'],
                'username' => $data['owner_username'], 'password' => $data['owner_password'],
                'phone' => $data['owner_phone'] ?? null, 'language' => 'bs',
                'country_code' => strtoupper($data['country_code']), 'is_active' => true, 'email_verified_at' => now(),
            ]);

            $warehouse = Warehouse::query()->create([
                'user_id' => $owner->id, 'name' => $data['company_name'],
                'email' => $data['company_email'] ?? null, 'phone' => $data['company_phone'] ?? null,
                'tax_number' => $data['tax_number'] ?? null, 'registration_number' => $data['registration_number'] ?? null,
                'country_code' => strtoupper($data['country_code']), 'city' => $data['city'] ?? null,
                'address' => $data['address'] ?? null,
                'total_capacity_pallets' => (int) ($data['total_capacity_pallets'] ?? 0),
                'storage_types' => $data['storage_types'] ?? null,
                'plan' => $data['plan'] ?? 'starter', 'status' => $data['status'] ?? 'pending',
            ]);

            return $warehouse->load($this->relations());
        });

        return $this->success((new EntityResource($warehouse))->resolve($request), 'Warehouse company and owner account created.', status: 201);
    }

    // Everything the "Moj Warehouse" dashboard renders, aggregated server-side from a single
    // inbound/outbound ledger (warehouse_movements) so the frontend never computes business figures
    // itself - it only fetches and displays this one payload.
    public function overview(Request $request): JsonResponse
    {
        $warehouse = Warehouse::query()->where('user_id', $request->user()->id)->first();

        if (! $warehouse) {
            return $this->success(['warehouse' => null], 'No warehouse found for this account.');
        }

        $completed = WarehouseMovement::query()->where('warehouse_id', $warehouse->id)->where('status', 'completed');

        $netByCustomer = (clone $completed)
            ->selectRaw("customer_name, SUM(CASE WHEN direction = 'inbound' THEN pallets ELSE -pallets END) as net_pallets")
            ->groupBy('customer_name')
            ->havingRaw('SUM(CASE WHEN direction = \'inbound\' THEN pallets ELSE -pallets END) > 0')
            ->orderByDesc('net_pallets')
            ->get();

        $netByStorageType = (clone $completed)
            ->selectRaw("storage_type, SUM(CASE WHEN direction = 'inbound' THEN pallets ELSE -pallets END) as net_pallets")
            ->groupBy('storage_type')
            ->havingRaw('SUM(CASE WHEN direction = \'inbound\' THEN pallets ELSE -pallets END) > 0')
            ->orderByDesc('net_pallets')
            ->get();

        $occupiedPallets = (int) $netByCustomer->sum('net_pallets');
        $totalCapacity = max(0, (int) $warehouse->total_capacity_pallets);
        $occupancyPercent = $totalCapacity > 0 ? min(100, round(($occupiedPallets / $totalCapacity) * 100, 1)) : 0.0;

        $today = Carbon::today();
        $todaysMovements = WarehouseMovement::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereDate('scheduled_at', $today)
            ->orderBy('scheduled_at')
            ->get();

        $recentArrivals = (clone $completed)
            ->where('direction', 'inbound')
            ->orderByDesc('completed_at')
            ->limit(5)
            ->get();

        $monthStart = Carbon::now()->startOfMonth();
        $storageRevenue = (clone $completed)
            ->where('direction', 'inbound')
            ->where('completed_at', '>=', $monthStart)
            ->sum('rate');
        $totalRevenue = (clone $completed)->where('direction', 'inbound')->sum('rate');

        return $this->success([
            'warehouse' => $warehouse,
            'stats' => [
                'occupancy_percent' => $occupancyPercent,
                'occupied_pallets' => $occupiedPallets,
                'available_pallets' => max(0, $totalCapacity - $occupiedPallets),
                'total_capacity_pallets' => $totalCapacity,
                'inbound_today' => $todaysMovements->where('direction', 'inbound')->count(),
                'outbound_today' => $todaysMovements->where('direction', 'outbound')->count(),
                'storage_revenue' => round((float) $storageRevenue, 2),
                'total_revenue' => round((float) $totalRevenue, 2),
                'currency' => $warehouse->movements()->value('currency') ?? 'EUR',
            ],
            'dock_schedule' => $todaysMovements->values(),
            'inventory_summary' => $netByStorageType->values(),
            'recent_arrivals' => $recentArrivals->values(),
            'top_customers' => $netByCustomer->take(5)->values(),
        ], 'Warehouse overview retrieved successfully.');
    }
}
