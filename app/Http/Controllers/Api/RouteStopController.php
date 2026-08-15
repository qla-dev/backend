<?php

namespace App\Http\Controllers\Api;

use App\Models\RouteStop;

class RouteStopController extends CrudController
{
    protected function modelClass(): string
    {
        return RouteStop::class;
    }

    protected function relations(): array
    {
        return ['route', 'loadStop'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['route_id' => [$p, 'integer', 'exists:routes,id'], 'load_stop_id' => ['nullable', 'integer', 'exists:load_stops,id'], 'position' => [$p, 'integer', 'min:1'], 'name' => [$p, 'string', 'max:255'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'estimated_at' => ['nullable', 'date'], 'arrived_at' => ['nullable', 'date'], 'note' => ['nullable', 'string']];
    }
}
