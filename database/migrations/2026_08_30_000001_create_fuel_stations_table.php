<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_stations', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 30)->default('fuelo');
            $table->string('source_type', 20)->default('station');
            $table->string('source_id', 100);
            $table->string('name')->nullable();
            $table->string('brand')->nullable();
            $table->string('operator')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('opening_hours')->nullable();
            $table->boolean('hgv')->nullable();
            $table->json('fuel_types')->nullable();
            $table->json('tags')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamp('last_synced_at');
            $table->timestamps();

            $table->unique(['source', 'source_type', 'source_id']);
            $table->index(['latitude', 'longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_stations');
    }
};
