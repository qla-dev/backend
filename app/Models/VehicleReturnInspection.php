<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleReturnInspection extends BaseModel
{
    protected function casts(): array
    {
        return [
            'mileage_km' => 'integer',
            'fuel_level_percent' => 'integer',
            'has_damage' => 'boolean',
            'inspected_at' => 'datetime',
        ];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(VehicleReturnPhoto::class);
    }
}
