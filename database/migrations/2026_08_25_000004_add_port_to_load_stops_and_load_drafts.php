<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sea shipments can carry both a port (POL/POD) and a separate door pickup/delivery address on
// the same stop (Door to Port / Port to Door), so the port is its own column rather than reusing
// `address` - mirrors the existing free-text `place_type` column style.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_stops', function (Blueprint $table): void {
            $table->string('port')->nullable()->after('place_type');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->string('pickup_port')->nullable()->after('pickup_place_type');
            $table->string('delivery_port')->nullable()->after('delivery_place_type');
        });
    }

    public function down(): void
    {
        Schema::table('load_stops', function (Blueprint $table): void {
            $table->dropColumn('port');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn(['pickup_port', 'delivery_port']);
        });
    }
};
