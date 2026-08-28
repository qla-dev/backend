<?php

namespace App\Http\Controllers\Api;

use App\Models\LoadDraft;

class LoadDraftController extends CrudController
{
    protected function modelClass(): string
    {
        return LoadDraft::class;
    }

    protected function relations(): array
    {
        return ['consignee'];
    }

    // A draft is never required to be complete, so every field is nullable regardless of whether
    // this is a create or an update — the caller only ever sends the fields it wants to set, and
    // CrudController's update()/store() already pass that straight through to Model::update()/
    // create() untouched, which is exactly the "simple patch" behavior needed here.
    protected function rules(bool $updating = false): array
    {
        return [
            'customer_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'consignee_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'assigned_driver_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'booking_reference' => ['nullable', 'string', 'max:160'],
            'insurance' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:120'],
            'freight_mode' => ['nullable', 'string', 'max:120'],
            'subdepartment' => ['nullable', 'string', 'max:120'],
            'transport_type' => ['nullable', 'in:road,air,sea,rail'],
            'cargo_type' => ['nullable', 'string', 'max:100'],
            'goods_type' => ['nullable', 'string', 'max:100'],
            'hs_codes' => ['nullable', 'array', 'max:20'],
            'hs_codes.*.code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'hs_codes.*.description' => ['nullable', 'string', 'max:1000'],
            'hs_codes.*.confidence' => ['nullable', 'numeric', 'between:0,1'],
            'hs_codes.*.chapterCode' => ['nullable', 'string', 'max:10'],
            'hs_codes.*.chapterName' => ['nullable', 'string', 'max:255'],
            'hs_codes.*.headingCode' => ['nullable', 'string', 'max:10'],
            'hs_codes.*.headingName' => ['nullable', 'string', 'max:255'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'length_m' => ['nullable', 'numeric', 'min:0'], 'width_m' => ['nullable', 'numeric', 'min:0'], 'height_m' => ['nullable', 'numeric', 'min:0'],
            'volume_m3' => ['nullable', 'numeric', 'min:0'], 'pallets' => ['nullable', 'integer', 'min:0'], 'quantity_measure' => ['nullable', 'string', 'max:255'],
            'teu' => ['nullable', 'string', 'max:80'], 'container_types' => ['nullable', 'string', 'max:255'], 'container_number' => ['nullable', 'string', 'max:255'],
            'etd_at' => ['nullable', 'date'], 'atd_at' => ['nullable', 'date'], 'transit_days' => ['nullable', 'integer', 'min:0', 'max:200'], 'shipper_name' => ['nullable', 'string', 'max:255'],
            'mediator' => ['nullable', 'string', 'max:255'], 'incoterms' => ['nullable', 'string', 'max:80'],
            'price_insurance' => ['nullable', 'string'], 'profit_loss' => ['nullable', 'string'], 'temperature_min' => ['nullable', 'numeric'],
            'temperature_max' => ['nullable', 'numeric'], 'declared_value' => ['nullable', 'numeric', 'min:0'], 'shipment_value_currency' => ['nullable', 'string', 'size:3'], 'budget' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'], 'payment_terms' => ['nullable', 'string', 'max:50'], 'payment_due_days' => ['nullable', 'integer', 'min:0'],
            'is_negotiable' => ['nullable', 'boolean'],
            'is_fragile' => ['nullable', 'boolean'], 'requires_food_grade' => ['nullable', 'boolean'], 'requires_adr' => ['nullable', 'boolean'], 'requires_tail_lift' => ['nullable', 'boolean'],
            'must_be_trackable' => ['nullable', 'boolean'], 'is_urgent' => ['nullable', 'boolean'], 'loading_methods' => ['nullable', 'array'],
            'toll_roads_included' => ['nullable', 'boolean'], 'ferry_included' => ['nullable', 'boolean'], 'cmr_required' => ['nullable', 'boolean'],
            'pallet_exchange_required' => ['nullable', 'boolean'], 'customs_required' => ['nullable', 'boolean'],
            'insurance_required' => ['nullable', 'boolean'], 'certification_required' => ['nullable', 'boolean'], 'inspection_services_required' => ['nullable', 'boolean'],
            'vehicle_type' => ['nullable', 'string', 'max:100'], 'transport_mode' => ['nullable', 'string', 'max:120'], 'special_requirements' => ['nullable', 'array'], 'special_requirements.*' => ['string', 'max:255'], 'characteristics' => ['nullable', 'array'], 'characteristics.*' => ['string', 'max:255'], 'delivery_proof' => ['nullable', 'string', 'max:30'],
            'body_types' => ['nullable', 'array'],
            'container_selections' => ['nullable', 'array'], 'container_selections.*.type' => ['nullable', 'string', 'max:20'], 'container_selections.*.quantity' => ['nullable', 'integer', 'min:1'],
            'bl_type' => ['nullable', 'string', 'max:30'],
            'dg_un_number' => ['nullable', 'string', 'max:20'], 'dg_imo_class' => ['nullable', 'string', 'max:20'], 'dg_packing_group' => ['nullable', 'string', 'max:10'], 'dg_proper_shipping_name' => ['nullable', 'string', 'max:255'],
            'oog_in_gauge' => ['nullable', 'string', 'max:20'], 'oog_length_m' => ['nullable', 'numeric', 'min:0'], 'oog_width_m' => ['nullable', 'numeric', 'min:0'], 'oog_height_m' => ['nullable', 'numeric', 'min:0'], 'oog_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'contact' => ['nullable', 'array'], 'notes' => ['nullable', 'string'], 'internal_comments' => ['nullable', 'string'],
            'external_comments' => ['nullable', 'string'],
            'pickup_place_type' => ['nullable', 'string', 'max:100'], 'pickup_city' => ['nullable', 'string', 'max:120'], 'pickup_country_code' => ['nullable', 'string', 'size:2'],
            'pickup_address' => ['nullable', 'string', 'max:255'], 'pickup_port' => ['nullable', 'string', 'max:255'], 'pickup_airport' => ['nullable', 'string', 'max:255'], 'pickup_latitude' => ['nullable', 'numeric', 'between:-90,90'], 'pickup_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'pickup_date' => ['nullable', 'date'], 'pickup_date_to' => ['nullable', 'date'], 'pickup_time_from' => ['nullable', 'date_format:H:i'], 'pickup_time_to' => ['nullable', 'date_format:H:i'],
            'delivery_place_type' => ['nullable', 'string', 'max:100'], 'delivery_city' => ['nullable', 'string', 'max:120'], 'delivery_country_code' => ['nullable', 'string', 'size:2'],
            'delivery_address' => ['nullable', 'string', 'max:255'], 'delivery_port' => ['nullable', 'string', 'max:255'], 'delivery_airport' => ['nullable', 'string', 'max:255'], 'delivery_latitude' => ['nullable', 'numeric', 'between:-90,90'], 'delivery_longitude' => ['nullable', 'numeric', 'between:-180,180'], 'delivery_radius_km' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'delivery_date' => ['nullable', 'date'], 'delivery_date_to' => ['nullable', 'date'], 'delivery_time_from' => ['nullable', 'date_format:H:i'], 'delivery_time_to' => ['nullable', 'date_format:H:i'],
        ];
    }
}
