<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Driver extends BaseModel
{
    protected function casts(): array
    {
        return ['license_expires_at' => 'date', 'certifications' => 'array', 'rating' => 'decimal:2'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function primaryCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'primary_company_id');
    }
}
