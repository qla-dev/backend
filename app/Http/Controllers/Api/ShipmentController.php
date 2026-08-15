<?php

namespace App\Http\Controllers\Api;

use App\Models\Shipment;

class ShipmentController extends CrudController
{
    protected function modelClass(): string
    {
        return Shipment::class;
    }

    protected function relations(): array
    {
        return ['freightLoad.stops', 'events'];
    }

    protected function searchColumns(): array
    {
        return ['tracking_number', 'carrier', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'tracking_number' => [$p, 'string', 'max:120'], 'carrier' => ['nullable', 'string', 'max:120'], 'status' => ['sometimes', 'string', 'max:50'], 'current_latitude' => ['nullable', 'numeric', 'between:-90,90'], 'current_longitude' => ['nullable', 'numeric', 'between:-180,180'], 'estimated_delivery_at' => ['nullable', 'date'], 'delivered_at' => ['nullable', 'date']];
    }
}
