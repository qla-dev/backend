<?php

namespace App\Models;

class FuelStation extends BaseModel
{
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'hgv' => 'boolean',
            'fuel_types' => 'array',
            'tags' => 'array',
            'raw_payload' => 'array',
            'source_updated_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }
}
