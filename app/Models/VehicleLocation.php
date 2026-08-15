<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleLocation extends BaseModel
{
    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
