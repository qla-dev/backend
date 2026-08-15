<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shipment extends BaseModel
{
    protected function casts(): array
    {
        return ['estimated_delivery_at' => 'datetime', 'delivered_at' => 'datetime'];
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TrackingEvent::class)->orderByDesc('occurred_at');
    }
}
