<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // A load can be made up of several packagings - 12 pallets and 3 crates, each with its own
        // size and weight. The first one stays in the load's own columns; the rest live here.
        foreach (['loads', 'load_drafts'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->json('extra_packaging')->nullable()->after('quantity_measure'));
        }
    }

    public function down(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('extra_packaging'));
        }
    }
};
