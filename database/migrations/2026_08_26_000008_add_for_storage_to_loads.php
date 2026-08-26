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
            $table->boolean('for_storage')->default(false)->index()->after('transport_type');
        });

        DB::table('loads')->where('transport_type', 'warehouse')->update(['for_storage' => true]);
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropIndex(['for_storage']);
            $table->dropColumn('for_storage');
        });
    }
};
