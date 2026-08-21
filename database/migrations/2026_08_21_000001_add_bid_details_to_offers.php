<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('price_basis', 30)->default('fixed_total')->after('amount');
            $table->string('vat', 10)->default('excluded')->after('currency');
            $table->string('payment_terms', 30)->nullable()->after('vat');
            $table->json('included_charges')->nullable()->after('valid_until');
            $table->json('excluded_charges')->nullable()->after('included_charges');
            $table->string('equipment_type', 60)->nullable()->after('excluded_charges');
            $table->string('vehicle_availability', 30)->nullable()->after('equipment_type');
            $table->date('available_date')->nullable()->after('vehicle_availability');
            $table->date('exact_loading_date')->nullable()->after('available_date');
            $table->unsignedSmallInteger('estimated_transit_days')->nullable()->after('exact_loading_date');
            $table->date('estimated_delivery_date')->nullable()->after('estimated_transit_days');
            $table->boolean('can_perform_as_required')->default(true)->after('estimated_delivery_date');
            $table->json('additional_charges')->nullable()->after('can_perform_as_required');
            $table->boolean('has_exceptions')->default(false)->after('additional_charges');
            $table->boolean('confirmed_authorized')->default(false)->after('message');
            $table->boolean('confirmed_details_match')->default(false)->after('confirmed_authorized');
            $table->boolean('confirmed_terms')->default(false)->after('confirmed_details_match');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropColumn([
                'price_basis', 'vat', 'payment_terms', 'included_charges', 'excluded_charges',
                'equipment_type', 'vehicle_availability', 'available_date', 'exact_loading_date',
                'estimated_transit_days', 'estimated_delivery_date', 'can_perform_as_required',
                'additional_charges', 'has_exceptions', 'confirmed_authorized', 'confirmed_details_match',
                'confirmed_terms',
            ]);
        });
    }
};
