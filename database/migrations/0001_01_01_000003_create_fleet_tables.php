<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('primary_company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->string('license_number')->unique();
            $table->string('license_country_code', 2);
            $table->date('license_expires_at');
            $table->string('availability_status')->default('available');
            $table->decimal('rating', 3, 2)->default(0);
            $table->unsignedInteger('completed_trips')->default(0);
            $table->json('certifications')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('registration_number')->unique();
            $table->string('vin')->nullable()->unique();
            $table->string('transport_type')->default('road');
            $table->string('vehicle_type');
            $table->string('make')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->decimal('capacity_kg', 12, 2)->nullable();
            $table->decimal('capacity_m3', 10, 2)->nullable();
            $table->string('status')->default('available');
            $table->json('features')->nullable();
            $table->timestamps();
        });

        Schema::create('fleet_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_dispatch')->default(false);
            $table->boolean('can_edit')->default(false);
            $table->timestamps();
            $table->unique(['vehicle_id', 'user_id']);
        });

        Schema::create('vehicle_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete()->cascadeOnUpdate();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->decimal('speed_kph', 7, 2)->nullable();
            $table->decimal('heading', 6, 2)->nullable();
            $table->timestamp('recorded_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_locations');
        Schema::dropIfExists('fleet_access');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('driver_profiles');
    }
};
