<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->boolean('toll_roads_included')->default(false)->after('characteristics');
            $table->boolean('ferry_included')->default(false)->after('toll_roads_included');
            $table->boolean('cmr_required')->default(true)->after('ferry_included');
            $table->boolean('pallet_exchange_required')->default(false)->after('cmr_required');
            $table->boolean('customs_required')->default(false)->after('pallet_exchange_required');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->dropColumn([
                'toll_roads_included', 'ferry_included', 'cmr_required',
                'pallet_exchange_required', 'customs_required',
            ]);
        });
    }
};
