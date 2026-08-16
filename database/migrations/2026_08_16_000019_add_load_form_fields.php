<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->char('shipment_value_currency', 3)->nullable()->after('declared_value');
            $table->string('vehicle_type', 100)->nullable()->after('loading_methods');
            $table->string('transport_mode', 120)->nullable()->after('vehicle_type');
            $table->json('special_requirements')->nullable()->after('transport_mode');
            $table->string('characteristics', 255)->nullable()->after('special_requirements');
            $table->string('delivery_proof', 30)->nullable()->after('characteristics');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->dropColumn(['shipment_value_currency', 'vehicle_type', 'transport_mode', 'special_requirements', 'characteristics', 'delivery_proof']);
        });
    }
};
