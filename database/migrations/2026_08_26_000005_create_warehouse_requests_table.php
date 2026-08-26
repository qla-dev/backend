<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Historical migration retained for databases that already ran it. The consolidation migration
// moves these records into `loads` and drops this table from the final schema.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->nullable();
            $table->foreignId('customer_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('title')->nullable();
            $table->string('status')->default('posted');
            $table->string('storage_type')->nullable();
            $table->unsignedInteger('pallets')->nullable();
            $table->decimal('cbm', 10, 2)->nullable();
            $table->decimal('weight_kg', 12, 2)->nullable();
            $table->string('city')->nullable();
            $table->string('country_code', 2)->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('is_ongoing')->default(false);
            $table->json('handling_requirements')->nullable();
            $table->decimal('temperature_min', 6, 2)->nullable();
            $table->decimal('temperature_max', 6, 2)->nullable();
            $table->boolean('requires_customs_bonded')->default(false);
            $table->boolean('requires_racking')->default(false);
            $table->boolean('requires_insurance')->default(false);
            $table->boolean('requires_security')->default(false);
            $table->decimal('budget', 14, 2)->nullable();
            $table->char('currency', 3)->default('EUR');
            $table->string('rate_unit')->nullable();
            $table->boolean('is_negotiable')->default(false);
            $table->text('notes')->nullable();
            $table->text('internal_comments')->nullable();
            $table->text('external_comments')->nullable();
            $table->json('contact')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouse_requests');
    }
};
