<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseMovement extends BaseModel
{
    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function warehouseRequest(): BelongsTo
    {
        return $this->belongsTo(WarehouseRequest::class);
    }
}
