<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WarehouseRequest extends BaseModel
{
    protected function casts(): array
    {
        return [
            'handling_requirements' => 'array', 'contact' => 'array',
            'is_ongoing' => 'boolean', 'requires_customs_bonded' => 'boolean', 'requires_racking' => 'boolean',
            'requires_insurance' => 'boolean', 'requires_security' => 'boolean', 'is_negotiable' => 'boolean',
            'start_date' => 'date', 'end_date' => 'date', 'published_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(WarehouseMovement::class);
    }
}
