<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The number printed on the document itself - a licence number, a passport number, an ADR
// certificate number. `name` is the uploaded filename and cannot carry it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('reference', 120)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('reference');
        });
    }
};
