<?php

namespace App\Http\Controllers\Api;

use App\Models\Route;

class RouteController extends CrudController
{
    protected function modelClass(): string
    {
        return Route::class;
    }

    protected function relations(): array
    {
        return ['freightLoad.stops', 'driver', 'vehicle', 'stops', 'events'];
    }

    protected function searchColumns(): array
    {
        return ['route_code', 'status'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'driver_user_id' => ['nullable', 'integer', 'exists:users,id'], 'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'], 'route_code' => [$p, 'string', 'max:100'], 'status' => ['sometimes', 'string', 'max:50'], 'distance_km' => ['nullable', 'numeric', 'min:0'], 'duration_minutes' => ['nullable', 'integer', 'min:0'], 'fuel_liters' => ['nullable', 'numeric', 'min:0'], 'estimated_cost' => ['nullable', 'numeric', 'min:0'], 'ai_confidence' => ['nullable', 'integer', 'between:0,100'], 'path' => ['nullable', 'array'], 'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date']];
    }
}
