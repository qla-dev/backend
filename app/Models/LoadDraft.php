<?php

namespace App\Models;

use App\Services\BookingReferenceGenerator;

class LoadDraft extends BaseModel
{
    protected function casts(): array
    {
        return [
            'loading_methods' => 'array', 'body_types' => 'array', 'special_requirements' => 'array', 'contact' => 'array', 'hs_codes' => 'array',
            'is_fragile' => 'boolean', 'requires_adr' => 'boolean', 'requires_tail_lift' => 'boolean',
            'must_be_trackable' => 'boolean', 'is_urgent' => 'boolean', 'is_negotiable' => 'boolean',
            'toll_roads_included' => 'boolean', 'ferry_included' => 'boolean', 'cmr_required' => 'boolean',
            'pallet_exchange_required' => 'boolean', 'customs_required' => 'boolean',
            'insurance_required' => 'boolean', 'certification_required' => 'boolean', 'inspection_services_required' => 'boolean',
            'etd_at' => 'datetime', 'atd_at' => 'datetime',
            'pickup_date' => 'date', 'pickup_date_to' => 'date', 'delivery_date' => 'date', 'delivery_date_to' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (LoadDraft $draft): void {
            if (blank($draft->booking_reference)) {
                $draft->booking_reference = app(BookingReferenceGenerator::class)->generate($draft->transport_type);
            }
        });

        static::updating(function (LoadDraft $draft): void {
            // A draft starts with an "FB-X-..." placeholder reference before its transport type
            // is known (see above). The first time transport_type actually gets set, upgrade to a
            // real typed reference from that type's own counter. A draft that already has a real
            // typed reference never gets reassigned, even if transport_type changes again later.
            if (! $draft->isDirty('transport_type') || blank($draft->transport_type)) {
                return;
            }
            $currentReference = (string) $draft->getOriginal('booking_reference');
            if ($currentReference !== '' && ! str_starts_with($currentReference, 'FB-X-')) {
                return;
            }
            $draft->booking_reference = app(BookingReferenceGenerator::class)->generate($draft->transport_type);
        });
    }
}
