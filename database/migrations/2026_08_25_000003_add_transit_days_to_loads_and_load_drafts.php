<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sea shipments show an expected POL-POD transit time instead of a driving distance (there is no
// "distance" between two ports the way there is between two road addresses).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->unsignedSmallInteger('transit_days')->nullable()->after('atd_at');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('transit_days')->nullable()->after('atd_at');
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table): void {
            $table->dropColumn('transit_days');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn('transit_days');
        });
    }
};
