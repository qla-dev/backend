<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoadDraft extends BaseModel
{
    protected function casts(): array
    {
        return [
            'extra_stops' => 'array', 'handling_equipment' => 'array', 'loading_methods' => 'array', 'body_types' => 'array', 'container_selections' => 'array', 'special_requirements' => 'array', 'characteristics' => 'array', 'contact' => 'array', 'hs_codes' => 'array', 'customs_documents' => 'array',
            'is_fragile' => 'boolean', 'requires_adr' => 'boolean', 'requires_tail_lift' => 'boolean',
            'must_be_trackable' => 'boolean', 'is_urgent' => 'boolean', 'is_negotiable' => 'boolean',
            'toll_roads_included' => 'boolean', 'ferry_included' => 'boolean', 'cmr_required' => 'boolean',
            'pallet_exchange_required' => 'boolean', 'customs_required' => 'boolean',
            'insurance_required' => 'boolean', 'certification_required' => 'boolean', 'inspection_services_required' => 'boolean',
            'etd_at' => 'datetime', 'atd_at' => 'datetime',
            'pickup_date' => 'date', 'pickup_date_to' => 'date', 'delivery_date' => 'date', 'delivery_date_to' => 'date',
            'storage_start_date' => 'date', 'storage_end_date' => 'date',
            'is_storage_ongoing' => 'boolean', 'requires_customs_bonded' => 'boolean',
            'requires_racking' => 'boolean', 'requires_security' => 'boolean',
        ];
    }

    public function consignee(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'consignee_customer_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
