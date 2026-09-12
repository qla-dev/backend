<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shipment_workspace', function (Blueprint $table): void {
            $table->json('additional_charges')->nullable()->after('offer_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_workspace', fn (Blueprint $table) => $table->dropColumn('additional_charges'));
    }
};
