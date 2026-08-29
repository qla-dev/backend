<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_return_inspections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->foreignId('load_id')->nullable()->unique()->constrained('loads')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('mileage_km');
            $table->unsignedTinyInteger('fuel_level_percent');
            $table->boolean('has_damage')->default(false);
            $table->text('damage_notes')->nullable();
            $table->string('parking_location')->nullable();
            $table->timestamp('inspected_at')->index();
            $table->timestamps();

            $table->index(['vehicle_id', 'inspected_at']);
        });

        Schema::create('vehicle_return_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_return_inspection_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('path', 500);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_return_photos');
        Schema::dropIfExists('vehicle_return_inspections');
    }
};
