<?php

namespace App\Models;

use App\Models\Concerns\HasReviews;
use App\Services\CustomsDocumentCatalog;
use App\Services\TrackingNumberGenerator;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Load extends BaseModel
{
    use HasReviews;

    public const STATUSES = [
        'posted',
        'booked',
        'opened',
        'sent',
        'in_delivery',
        'received',
        'finished',
        'pending',
        'cancelled',
    ];

    public const PRE_DELIVERY_STATUSES = ['published', 'open_for_reservations', 'reservation_selected', 'booking_confirmed'];

    public const BOOKING_STATUSES = ['confirmed', 'in_execution', 'completed', 'cancelled'];

    protected function casts(): array
    {
        return [
            'loading_methods' => 'array', 'body_types' => 'array', 'container_selections' => 'array', 'special_requirements' => 'array', 'characteristics' => 'array', 'contact' => 'array', 'hs_codes' => 'array', 'customs_documents' => 'array',
            'handling_requirements' => 'array',
            'is_fragile' => 'boolean', 'requires_adr' => 'boolean', 'requires_tail_lift' => 'boolean',
            'must_be_trackable' => 'boolean', 'is_urgent' => 'boolean', 'is_negotiable' => 'boolean', 'for_storage' => 'boolean', 'published_at' => 'datetime', 'completed_at' => 'datetime',
            'toll_roads_included' => 'boolean', 'ferry_included' => 'boolean', 'cmr_required' => 'boolean',
            'pallet_exchange_required' => 'boolean', 'customs_required' => 'boolean',
            'insurance_required' => 'boolean', 'certification_required' => 'boolean', 'inspection_services_required' => 'boolean',
            'status_change' => 'array',
            'etd_at' => 'datetime', 'atd_at' => 'datetime',
            'storage_start_date' => 'date', 'storage_end_date' => 'date', 'is_storage_ongoing' => 'boolean',
            'requires_customs_bonded' => 'boolean', 'requires_racking' => 'boolean', 'requires_security' => 'boolean', 'requires_food_grade' => 'boolean',
        ];
    }

    protected function customsDocuments(): Attribute
    {
        return Attribute::make(
            get: function (mixed $value): array {
                $stored = is_string($value) ? json_decode($value, true) : $value;
                $codes = collect($this->hs_codes ?? [])
                    ->map(fn (mixed $item): string => is_array($item) ? (string) ($item['code'] ?? '') : (string) $item)
                    ->filter()
                    ->values()
                    ->all();

                return app(CustomsDocumentCatalog::class)->resolve(
                    $codes,
                    is_array($stored) ? $stored : [],
                );
            },
        );
    }

    protected static function booted(): void
    {
        static::creating(function (Load $load): void {
            if (! $load->consignee_customer_id && $load->customer_user_id) {
                $owner = User::query()->findOrFail($load->customer_user_id);
                $recipient = Customer::query()->firstOrCreate(
                    ['user_id' => $owner->id],
                    ['name' => $owner->name, 'email' => $owner->email, 'phone' => $owner->phone, 'country_code' => $owner->country_code],
                );
                $load->consignee_customer_id = $recipient->id;
            }
            $load->status_change ??= [$load->status => now()->toIso8601String()];

        });

        // A tracking number belongs to every load, regardless of whether it was published
        // manually, restored from a draft, created by LenaAI, or imported in bulk.
        static::created(function (Load $load): void {
            $load->shipment()->create([
                'tracking_number' => app(TrackingNumberGenerator::class)->generate($load->transport_type),
            ]);
        });

        static::updating(function (Load $load): void {
            if ($load->isDirty('status')) {
                $history = $load->status_change ?? [];
                $history[$load->status] = now()->toIso8601String();
                $load->status_change = $history;
                $load->booking_status = match ($load->status) {
                    'booked' => 'confirmed',
                    'in_delivery' => 'in_execution',
                    'finished' => 'completed',
                    'cancelled' => 'cancelled',
                    default => $load->booking_status,
                };
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

    public function shipmentWorkspace(): HasOne
    {
        return $this->hasOne(ShipmentWorkspace::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(LoadNote::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function warehouseMovements(): HasMany
    {
        return $this->hasMany(WarehouseMovement::class);
    }

    public function vehicleReturnInspection(): HasOne
    {
        return $this->hasOne(VehicleReturnInspection::class);
    }
}
