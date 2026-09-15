<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A position is a fact about the truck, but it is reported from someone's phone - and a truck can be
 * driven by more than one person. Recording who sent each fix keeps "who was driving" answerable
 * after the fact. Nullable: existing rows have no reporter, and deleting a user keeps their trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_locations', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('vehicle_id')->constrained('users')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_locations', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
