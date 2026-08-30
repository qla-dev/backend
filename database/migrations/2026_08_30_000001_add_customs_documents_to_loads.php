<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->json('customs_documents')->nullable()->after('hs_codes');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->json('customs_documents')->nullable()->after('hs_codes');
        });
    }

    public function down(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn('customs_documents');
        });

        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('customs_documents');
        });
    }
};
