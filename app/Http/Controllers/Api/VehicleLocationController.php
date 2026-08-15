<?php

namespace App\Http\Controllers\Api;

use App\Models\VehicleLocation;

class VehicleLocationController extends CrudController
{
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
}
