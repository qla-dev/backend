<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'total_capacity_pallets' => ['nullable', 'integer', 'min:0'],
            'storage_types' => ['nullable', 'array'],
            'certifications' => ['nullable', 'array'],
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $data['user_id'] = $data['user_id'] ?? $request->user()->id;

        $warehouse = Warehouse::query()->create($data);

        return $this->success((new EntityResource($warehouse))->resolve($request), 'Warehouse created successfully.', status: 201);
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
