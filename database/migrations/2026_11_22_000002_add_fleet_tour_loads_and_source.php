<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_tours', function (Blueprint $table): void {
            $table->string('import_source', 20)->nullable();
            $table->json('source_data')->nullable();
        });

        Schema::create('fleet_tour_loads', function (Blueprint $table): void {
            $table->foreignId('tour_id')->constrained('fleet_tours')->cascadeOnDelete();
            $table->foreignId('load_id')->constrained('loads')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->primary(['tour_id', 'load_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_tour_loads');
        Schema::table('fleet_tours', fn (Blueprint $table) => $table->dropColumn(['import_source', 'source_data']));
    }
};
