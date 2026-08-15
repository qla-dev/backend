<?php

namespace App\Http\Controllers\Api;

use App\Models\TrackingEvent;

class TrackingEventController extends CrudController
{
    protected function modelClass(): string
    {
        return TrackingEvent::class;
    }

    protected function relations(): array
    {
        return ['shipment.freightLoad', 'route', 'vehicle', 'creator'];
    }

    protected function searchColumns(): array
    {
        return ['status', 'title', 'location'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['shipment_id' => [$p, 'integer', 'exists:shipments,id'], 'route_id' => ['nullable', 'integer', 'exists:routes,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'], 'created_by_user_id' => ['nullable', 'integer', 'exists:users,id'], 'status' => [$p, 'string', 'max:50'], 'title' => [$p, 'string', 'max:255'], 'description' => ['nullable', 'string'], 'location' => ['nullable', 'string', 'max:255'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'metadata' => ['nullable', 'array'], 'occurred_at' => [$p, 'date']];
    }
}
