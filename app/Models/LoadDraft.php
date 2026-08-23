<?php

namespace App\Models;

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
}
