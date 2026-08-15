<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loads', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('assigned_driver_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->string('status')->default('available')->index();
            $table->string('transport_type')->default('road');
            $table->string('cargo_type');
            $table->string('goods_type')->nullable();
            $table->decimal('weight_kg', 12, 2);
            $table->decimal('length_m', 10, 2)->nullable();
            $table->decimal('width_m', 10, 2)->nullable();
            $table->decimal('height_m', 10, 2)->nullable();
            $table->decimal('volume_m3', 10, 2)->nullable();
            $table->unsignedInteger('pallets')->nullable();
            $table->decimal('temperature_min', 6, 2)->nullable();
            $table->decimal('temperature_max', 6, 2)->nullable();
            $table->decimal('declared_value', 14, 2)->nullable();
            $table->decimal('budget', 14, 2)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('payment_terms')->default('negotiable');
            $table->unsignedSmallInteger('payment_due_days')->nullable();
            $table->boolean('is_fragile')->default(false);
            $table->boolean('requires_adr')->default(false);
            $table->boolean('requires_tail_lift')->default(false);
            $table->boolean('must_be_trackable')->default(false);
            $table->boolean('is_urgent')->default(false);
            $table->json('loading_methods')->nullable();
            $table->json('body_types')->nullable();
            $table->json('contact')->nullable();
            $table->text('notes')->nullable();
            $table->text('internal_comments')->nullable();
            $table->text('external_comments')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('load_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('type');
            $table->unsignedSmallInteger('position');
            $table->string('place_type')->nullable();
            $table->string('city');
            $table->string('country_code', 2);
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('window_starts_at')->nullable();
            $table->timestamp('window_ends_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['load_id', 'position']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->decimal('amount', 14, 2);
            $table->char('currency', 3)->default('EUR');
            $table->string('status')->default('pending');
            $table->timestamp('valid_until')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });

        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->unique()->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('tracking_number')->unique();
            $table->string('carrier')->nullable();
            $table->string('status')->default('pending')->index();
            $table->decimal('current_latitude', 10, 7)->nullable();
            $table->decimal('current_longitude', 10, 7)->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('driver_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete()->cascadeOnUpdate();
            $table->string('route_code')->unique();
            $table->string('status')->default('planned');
            $table->decimal('distance_km', 10, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->decimal('fuel_liters', 10, 2)->nullable();
            $table->decimal('estimated_cost', 14, 2)->nullable();
            $table->unsignedTinyInteger('ai_confidence')->nullable();
            $table->json('path')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('route_stops', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('load_stop_id')->nullable()->constrained('load_stops')->nullOnDelete()->cascadeOnUpdate();
            $table->unsignedSmallInteger('position');
            $table->string('name');
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->timestamp('estimated_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['route_id', 'position']);
        });

        Schema::create('tracking_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained('shipments')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('status');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
        });

        Schema::create('load_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('author_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('priority')->default('medium');
            $table->text('body');
            $table->boolean('is_private')->default(false);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('load_id')->nullable()->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('type');
            $table->string('name');
            $table->string('path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('load_notes');
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('route_stops');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('load_stops');
        Schema::dropIfExists('loads');
    }
};
