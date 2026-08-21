<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Offer extends BaseModel
{
    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime', 'amount' => 'decimal:2',
            'included_charges' => 'array', 'excluded_charges' => 'array', 'additional_charges' => 'array',
            'available_date' => 'date', 'exact_loading_date' => 'date', 'estimated_delivery_date' => 'date',
            'can_perform_as_required' => 'boolean', 'has_exceptions' => 'boolean',
            'confirmed_authorized' => 'boolean', 'confirmed_details_match' => 'boolean', 'confirmed_terms' => 'boolean',
        ];
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
