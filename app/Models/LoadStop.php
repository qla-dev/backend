<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadStop extends BaseModel
{
    protected function casts(): array
    {
        return ['window_starts_at' => 'datetime', 'window_ends_at' => 'datetime', 'arrived_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }
}
