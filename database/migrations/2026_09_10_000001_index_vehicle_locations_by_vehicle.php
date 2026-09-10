<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tracked shipment writes its vehicle's position once a second, so this table grows by thousands
 * of rows per driving day while every read of it - the latest fix for a vehicle, the trail behind
 * one - filters by vehicle and orders by time. The existing index covers `recorded_at` alone, which
 * those reads cannot use to narrow to a vehicle first.
 *
 * Deliberately not unique: an index that rejects duplicates could not be added to a table that may
 * already hold some, and removing rows to make one fit is not something a migration should do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_locations', function (Blueprint $table): void {
            $table->index(['vehicle_id', 'recorded_at'], 'vehicle_locations_vehicle_recorded_index');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_locations', function (Blueprint $table): void {
            $table->dropIndex('vehicle_locations_vehicle_recorded_index');
        });
    }
};
