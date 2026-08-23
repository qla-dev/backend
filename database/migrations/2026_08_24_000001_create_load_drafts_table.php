<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Structural clone of `loads` (see 0001_01_01_000004_create_logistics_tables.php plus the later
// loads-altering migrations) for an in-progress, unpublished draft being built through the LenaAI
// canvas. Everything here is nullable and every foreign key is nullOnDelete: a draft is allowed to
// be incomplete at any point, unlike a real load. Publish-lifecycle columns (status, status_change,
// published_at, completed_at, the public_id uniqueness constraint) are dropped since a draft never
// goes through that lifecycle. Route data is flattened into pickup_*/delivery_* columns instead of
// a separate load_stops-style table, mirroring ScanFieldPatch's shape.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('load_drafts', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('consignee_customer_id')->nullable()->constrained('customers')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete()->cascadeOnUpdate();
            $table->string('title')->nullable();
            $table->string('booking_reference', 160)->nullable();
            $table->string('insurance')->nullable();
            $table->string('department', 120)->nullable();
            $table->string('freight_mode', 120)->nullable();
            $table->string('subdepartment', 120)->nullable();
            $table->string('transport_type')->nullable();
            $table->string('cargo_type')->nullable();
            $table->string('goods_type')->nullable();
            $table->json('hs_codes')->nullable();
            $table->decimal('weight_kg', 12, 2)->nullable();
            $table->decimal('length_m', 10, 2)->nullable();
            $table->decimal('width_m', 10, 2)->nullable();
            $table->decimal('height_m', 10, 2)->nullable();
            $table->decimal('volume_m3', 10, 2)->nullable();
            $table->unsignedInteger('pallets')->nullable();
            $table->string('quantity_measure')->nullable();
            $table->string('teu', 80)->nullable();
            $table->string('container_types')->nullable();
            $table->string('container_number')->nullable();
            $table->timestamp('etd_at')->nullable();
            $table->timestamp('atd_at')->nullable();
            $table->string('shipper_name')->nullable();
            $table->string('mediator')->nullable();
            $table->string('incoterms', 80)->nullable();
            $table->text('price_insurance')->nullable();
            $table->text('profit_loss')->nullable();
            $table->decimal('temperature_min', 6, 2)->nullable();
            $table->decimal('temperature_max', 6, 2)->nullable();
            $table->decimal('declared_value', 14, 2)->nullable();
            $table->char('shipment_value_currency', 3)->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->boolean('is_negotiable')->default(true);
            $table->char('currency', 3)->default('EUR');
            $table->string('payment_terms')->nullable();
            $table->unsignedSmallInteger('payment_due_days')->nullable();
            $table->boolean('is_fragile')->default(false);
            $table->boolean('requires_adr')->default(false);
            $table->boolean('requires_tail_lift')->default(false);
            $table->boolean('must_be_trackable')->default(false);
            $table->boolean('is_urgent')->default(false);
            $table->json('loading_methods')->nullable();
            $table->string('vehicle_type', 100)->nullable();
            $table->string('transport_mode', 120)->nullable();
            $table->json('special_requirements')->nullable();
            $table->string('characteristics', 255)->nullable();
            $table->string('delivery_proof', 30)->nullable();
            $table->boolean('toll_roads_included')->default(false);
            $table->boolean('ferry_included')->default(false);
            $table->boolean('cmr_required')->default(true);
            $table->boolean('pallet_exchange_required')->default(false);
            $table->boolean('customs_required')->default(false);
            $table->boolean('insurance_required')->default(false);
            $table->boolean('certification_required')->default(false);
            $table->boolean('inspection_services_required')->default(false);
            $table->json('body_types')->nullable();
            $table->json('contact')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_comments')->nullable();
            $table->text('external_comments')->nullable();

            // Flattened route (loads keeps this in the separate load_stops table).
            $table->string('pickup_place_type')->nullable();
            $table->string('pickup_city')->nullable();
            $table->string('pickup_country_code', 2)->nullable();
            $table->string('pickup_address')->nullable();
            $table->decimal('pickup_latitude', 10, 7)->nullable();
            $table->decimal('pickup_longitude', 10, 7)->nullable();
            $table->date('pickup_date')->nullable();
            $table->date('pickup_date_to')->nullable();
            $table->time('pickup_time_from')->nullable();
            $table->time('pickup_time_to')->nullable();
            $table->string('delivery_place_type')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_country_code', 2)->nullable();
            $table->string('delivery_address')->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->date('delivery_date')->nullable();
            $table->date('delivery_date_to')->nullable();
            $table->time('delivery_time_from')->nullable();
            $table->time('delivery_time_to')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('load_drafts');
    }
};
