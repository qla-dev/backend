<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['loads', 'load_drafts'] as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->string('dimension_scope', 20)->default('overall');
            });
        }
    }

    public function down(): void
    {
        foreach (['loads', 'load_drafts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('dimension_scope'));
        }
    }
};
