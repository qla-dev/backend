<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Route extends BaseModel
{
    protected function casts(): array
    {
        return ['path' => 'array', 'starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(RouteStop::class)->orderBy('position');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrackingEvent::class);
    }
}
