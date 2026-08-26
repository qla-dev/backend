<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Air shipments can carry both an airport (AOL/AOD) and a separate door pickup/delivery address
// on the same stop (Address + Last Mile Delivery), so the airport is its own column rather than
// reusing `address` - mirrors the sea `port` column added in
// 2026_08_25_000004_add_port_to_load_stops_and_load_drafts.php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_stops', function (Blueprint $table): void {
            $table->string('airport')->nullable()->after('port');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->string('pickup_airport')->nullable()->after('pickup_port');
            $table->string('delivery_airport')->nullable()->after('delivery_port');
        });
    }

    public function down(): void
    {
        Schema::table('load_stops', function (Blueprint $table): void {
            $table->dropColumn('airport');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn(['pickup_airport', 'delivery_airport']);
        });
    }
};
