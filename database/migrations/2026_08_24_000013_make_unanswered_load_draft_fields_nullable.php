<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->boolean('is_negotiable')->nullable()->default(null)->change();
            $table->boolean('cmr_required')->nullable()->default(null)->change();
        });

        // Earlier empty chat drafts inherited true/true from the schema. Reset only drafts that
        // contain no actual load information; answered or manually completed drafts stay intact.
        DB::table('load_drafts')
            ->whereNull('title')
            ->whereNull('consignee_customer_id')
            ->whereNull('transport_type')
            ->whereNull('cargo_type')
            ->whereNull('goods_type')
            ->whereNull('weight_kg')
            ->whereNull('pickup_city')
            ->whereNull('delivery_city')
            ->whereNull('budget')
            ->whereNull('notes')
            ->update([
                'is_negotiable' => null,
                'cmr_required' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('load_drafts')->whereNull('is_negotiable')->update(['is_negotiable' => true]);
        DB::table('load_drafts')->whereNull('cmr_required')->update(['cmr_required' => true]);

        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->boolean('is_negotiable')->nullable(false)->default(true)->change();
            $table->boolean('cmr_required')->nullable(false)->default(true)->change();
        });
    }
};
