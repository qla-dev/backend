<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends BaseModel
{
    protected function casts(): array
    {
        return ['valid_until' => 'datetime', 'amount' => 'decimal:2'];
    }

    public function freightLoad(): BelongsTo
    {
        return $this->belongsTo(Load::class, 'load_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
