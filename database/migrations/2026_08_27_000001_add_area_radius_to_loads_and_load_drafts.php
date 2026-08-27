<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A storage request usually has no exact warehouse address yet - the customer only knows the
     * area the goods should be stored in ("Sarajevo, 30 km around"). The radius turns the single
     * warehouse_latitude/longitude point into that search area for warehouse companies.
     */
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->unsignedSmallInteger('warehouse_radius_km')->nullable()->after('warehouse_longitude');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('delivery_radius_km')->nullable()->after('delivery_longitude');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('warehouse_radius_km');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn('delivery_radius_km');
        });
    }
};
