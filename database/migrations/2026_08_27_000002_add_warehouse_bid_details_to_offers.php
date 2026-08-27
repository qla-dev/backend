<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A bid on a warehousing request answers a different question than a transport bid does - not
// "which truck, leaving when", but "can I take it, from when, how much space, at what rate per
// unit". These columns carry that answer; the transport columns simply stay null on such offers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->string('capacity_status', 30)->nullable()->after('can_perform_as_required');
            $table->date('available_from')->nullable()->after('capacity_status');
            $table->decimal('available_capacity', 12, 2)->nullable()->after('available_from');
            $table->string('capacity_unit', 30)->nullable()->after('available_capacity');
            $table->string('minimum_storage_period', 30)->nullable()->after('capacity_unit');
            $table->json('price_breakdown')->nullable()->after('minimum_storage_period');
            $table->json('services_included')->nullable()->after('price_breakdown');
            $table->json('optional_conditions')->nullable()->after('services_included');
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('optional_conditions');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropColumn([
                'capacity_status', 'available_from', 'available_capacity', 'capacity_unit',
                'minimum_storage_period', 'price_breakdown', 'services_included',
                'optional_conditions', 'warehouse_id',
            ]);
        });
    }
};
