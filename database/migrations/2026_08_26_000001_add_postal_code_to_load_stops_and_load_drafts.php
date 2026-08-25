<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The city field used to double as a combined "city or postcode" input - this gives postal code
// its own column so it can be captured, AI-detected, and reused (e.g. for a last-mile draft)
// independently of the city name.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_stops', function (Blueprint $table): void {
            $table->string('postal_code')->nullable()->after('city');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->string('pickup_postal_code')->nullable()->after('pickup_city');
            $table->string('delivery_postal_code')->nullable()->after('delivery_city');
        });
    }

    public function down(): void
    {
        Schema::table('load_stops', function (Blueprint $table): void {
            $table->dropColumn('postal_code');
        });

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn(['pickup_postal_code', 'delivery_postal_code']);
        });
    }
};
