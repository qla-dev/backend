<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->timestamp('status_change')->nullable()->after('status');
        });

        DB::table('loads')->update(['status_change' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('status_change');
        });
    }
};
