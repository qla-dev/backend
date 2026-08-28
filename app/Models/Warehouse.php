<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Warehouse extends BaseModel
{
    protected function casts(): array
    {
        return [
            'storage_types' => 'array', 'certifications' => 'array',
            'utilization_thresholds' => 'array', 'storage_config' => 'array', 'temperature_zones' => 'array',
            'inventory_settings' => 'array', 'equipment' => 'array', 'handling_capabilities' => 'array',
            'operations' => 'array', 'capabilities' => 'array', 'technology' => 'array',
            'compliance' => 'array', 'standards' => 'array', 'documents' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(WarehouseMovement::class);
    }
}
