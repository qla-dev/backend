<?php

namespace App\Services;

use App\Models\Load;
use App\Models\Offer;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Illuminate\Validation\ValidationException;

class WarehouseMovementCreator
{
    /** Called inside the approval transaction, with the load locked by the caller. */
    public function create(Load $load, Offer $offer): ?WarehouseMovement
    {
        if (! $load->for_storage && $load->transport_type !== 'warehouse') {
            return null;
        }

        if (! $offer->warehouse_id || ! Warehouse::query()->whereKey($offer->warehouse_id)->exists()) {
            throw ValidationException::withMessages(['warehouse_id' => 'The accepted storage offer must identify a warehouse.']);
        }

        $scheduledAt = $offer->available_from ?? $load->storage_start_date;
        if (! $scheduledAt) {
            throw ValidationException::withMessages(['available_from' => 'The accepted storage offer must identify an arrival date.']);
        }

        // Approval schedules arrival; only receiving the goods should increase stock.
        // Preserve any existing receipt instead of resetting its operational progress.
        return WarehouseMovement::query()->firstOrCreate([
            'warehouse_id' => $offer->warehouse_id,
            'load_id' => $load->id,
            'direction' => 'inbound',
        ], [
            'status' => 'booked',
            'scheduled_at' => $scheduledAt,
            'customer_name' => $load->customer?->name,
            'storage_type' => $load->storage_type,
            'pallets' => $load->pallets ?? 0,
            'cbm' => $load->volume_m3,
            'weight_kg' => $load->weight_kg,
            'rate' => $offer->amount,
            'currency' => $offer->currency,
            'description' => $load->title,
        ]);
    }
}
