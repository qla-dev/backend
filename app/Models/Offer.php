<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends BaseModel
{
    protected function casts(): array
    {
        return [
            'valid_until' => 'datetime', 'amount' => 'decimal:2',
            'included_charges' => 'array', 'excluded_charges' => 'array', 'additional_charges' => 'array',
            'available_date' => 'date', 'exact_loading_date' => 'date', 'estimated_delivery_date' => 'date',
            'can_perform_as_required' => 'boolean', 'has_exceptions' => 'boolean', 'is_counter' => 'boolean',
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

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function parentOffer(): BelongsTo
    {
        return $this->belongsTo(Offer::class, 'parent_offer_id');
    }

    public function counterOffers(): HasMany
    {
        return $this->hasMany(Offer::class, 'parent_offer_id');
    }
}
