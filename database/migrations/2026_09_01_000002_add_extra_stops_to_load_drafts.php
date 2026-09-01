<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A road load can be multi-drop: several pickup addresses, several delivery addresses. A published
// load spreads those over the load_stops table, but a draft flattens its route into pickup_*/
// delivery_* columns (see the create migration), which only hold one stop of each side. The stops
// beyond those two are kept here as JSON so an unfinished multi-drop route is not silently reduced
// to its first pickup and first delivery when it is saved.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->json('extra_stops')->nullable()->after('delivery_time_to');
        });
    }

    public function down(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn('extra_stops');
        });
    }
};
