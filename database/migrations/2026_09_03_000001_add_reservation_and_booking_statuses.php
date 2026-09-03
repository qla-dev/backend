<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->string('pre_delivery_status', 40)->nullable()->index()->after('status');
            $table->string('booking_status', 30)->nullable()->index()->after('pre_delivery_status');
        });
        Schema::table('offers', function (Blueprint $table) {
            $table->string('request_type', 30)->default('price_offer')->index()->after('load_id');
        });
        DB::table('loads')->where('status', 'posted')->whereNull('pre_delivery_status')
            ->update(['pre_delivery_status' => 'open_for_reservations']);
    }

    public function down(): void
    {
        Schema::table('offers', fn (Blueprint $table) => $table->dropColumn('request_type'));
        Schema::table('loads', fn (Blueprint $table) => $table->dropColumn(['pre_delivery_status', 'booking_status']));
    }
};
