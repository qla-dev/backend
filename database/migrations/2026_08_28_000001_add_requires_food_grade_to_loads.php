<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// "Food / Pharma" is one of the special requirements a storage request states, and it has no
// equivalent among the transport flags - a warehouse either holds a food-grade or pharma licence
// or it does not, which is what decides whether it can bid at all.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'requires_food_grade')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->boolean('requires_food_grade')->default(false)->after('is_fragile');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'requires_food_grade')) {
                Schema::table($table, function (Blueprint $blueprint): void {
                    $blueprint->dropColumn('requires_food_grade');
                });
            }
        }
    }
};
