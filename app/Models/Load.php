<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Load extends BaseModel
{
    protected function casts(): array
    {
        return [
            'loading_methods' => 'array', 'body_types' => 'array', 'contact' => 'array',
            'is_fragile' => 'boolean', 'requires_adr' => 'boolean', 'requires_tail_lift' => 'boolean',
            'must_be_trackable' => 'boolean', 'is_urgent' => 'boolean', 'published_at' => 'datetime', 'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_user_id');
    }

    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'consignee_customer_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assignedDriver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function stops(): HasMany
    {
        return $this->hasMany(LoadStop::class)->orderBy('position');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function routes(): HasMany
    {
        return $this->hasMany(Route::class);
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LoadNote::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }
}
