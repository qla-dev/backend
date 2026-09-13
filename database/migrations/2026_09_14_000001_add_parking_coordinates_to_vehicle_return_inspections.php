<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // The parking spot is picked on a map now, so the record keeps the exact point the
        // driver marked next to the address they typed.
        Schema::table('vehicle_return_inspections', function (Blueprint $table): void {
            $table->decimal('parking_latitude', 10, 7)->nullable()->after('parking_location');
            $table->decimal('parking_longitude', 10, 7)->nullable()->after('parking_latitude');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_return_inspections', fn (Blueprint $table) => $table->dropColumn(['parking_latitude', 'parking_longitude']));
    }
};
