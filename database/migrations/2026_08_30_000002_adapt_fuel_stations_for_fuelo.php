<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fuel_stations', function (Blueprint $table): void {
            $table->string('source', 30)->default('fuelo')->change();
            $table->string('source_type', 20)->default('station')->change();
            $table->string('source_id', 100)->change();

            if (! Schema::hasColumn('fuel_stations', 'address')) {
                $table->string('address')->nullable()->after('operator');
            }
            if (! Schema::hasColumn('fuel_stations', 'raw_payload')) {
                $table->json('raw_payload')->nullable()->after('tags');
            }
        });
    }

    public function down(): void
    {
        // Fuelo identifiers can be non-numeric, so source_id must not be narrowed on rollback.
        Schema::table('fuel_stations', function (Blueprint $table): void {
            $table->string('source', 30)->default('openstreetmap')->change();
            $table->string('source_type', 20)->default(null)->change();
        });
    }
};
