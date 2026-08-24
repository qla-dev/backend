<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Load extends BaseModel
{
    public const STATUSES = [
        'posted',
        'opened',
        'sent',
        'in_delivery',
        'received',
        'finished',
        'pending',
        'cancelled',
    ];

    protected function casts(): array
    {
        return [
            'loading_methods' => 'array', 'body_types' => 'array', 'special_requirements' => 'array', 'contact' => 'array', 'hs_codes' => 'array',
            'is_fragile' => 'boolean', 'requires_adr' => 'boolean', 'requires_tail_lift' => 'boolean',
            'must_be_trackable' => 'boolean', 'is_urgent' => 'boolean', 'is_negotiable' => 'boolean', 'published_at' => 'datetime', 'completed_at' => 'datetime',
            'toll_roads_included' => 'boolean', 'ferry_included' => 'boolean', 'cmr_required' => 'boolean',
            'pallet_exchange_required' => 'boolean', 'customs_required' => 'boolean',
            'insurance_required' => 'boolean', 'certification_required' => 'boolean', 'inspection_services_required' => 'boolean',
            'status_change' => 'array',
            'etd_at' => 'datetime', 'atd_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Load $load): void {
            $load->status_change ??= [$load->status => now()->toIso8601String()];

        });

        static::updating(function (Load $load): void {
            if ($load->isDirty('status')) {
                $history = $load->status_change ?? [];
                $history[$load->status] = now()->toIso8601String();
                $load->status_change = $history;
            }
        });
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
