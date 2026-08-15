<?php

namespace App\Http\Controllers\Api;

use App\Models\LoadStop;

class LoadStopController extends CrudController
{
    protected function modelClass(): string
    {
        return LoadStop::class;
    }

    protected function relations(): array
    {
        return ['freightLoad'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['load_id' => [$p, 'integer', 'exists:loads,id'], 'type' => [$p, 'in:pickup,waypoint,delivery'], 'position' => [$p, 'integer', 'min:1'], 'place_type' => ['nullable', 'string', 'max:100'], 'city' => [$p, 'string', 'max:120'], 'country_code' => [$p, 'string', 'size:2'], 'address' => ['nullable', 'string', 'max:255'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'], 'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'window_starts_at' => ['nullable', 'date'], 'window_ends_at' => ['nullable', 'date'], 'arrived_at' => ['nullable', 'date'], 'completed_at' => ['nullable', 'date']];
    }
}
