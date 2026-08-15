<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RouteStop extends BaseModel
{
    protected function casts(): array
    {
        return ['estimated_at' => 'datetime', 'arrived_at' => 'datetime'];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function loadStop(): BelongsTo
    {
        return $this->belongsTo(LoadStop::class);
    }
}
