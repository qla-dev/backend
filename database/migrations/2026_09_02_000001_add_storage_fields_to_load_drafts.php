<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A storage request can now be saved as a draft like any other load.
//
// `load_drafts` was built as a structural clone of `loads` before warehouse requests existed, so it
// carries every transport field but none of the storage ones - the storage type, how long the goods
// stay, how the rate is charged and the bonded/racking/security requirements. Saving a warehouse
// draft without these would keep the title, cargo, route and contact while silently dropping every
// answer that makes it a storage request, which is worse than not offering the button at all.
//
// The generic columns a storage request shares with a transport load (temperature, ADR, insurance,
// fragile, pallets, volume, weight) already exist and are reused rather than duplicated here.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->string('storage_type')->nullable()->after('goods_type');
            $table->date('storage_start_date')->nullable()->after('storage_type');
            $table->date('storage_end_date')->nullable()->after('storage_start_date');
            // An open-ended request has a start but no end; null end alone cannot say whether that
            // is "ongoing" or simply "not answered yet", which a draft has to keep apart.
            $table->boolean('is_storage_ongoing')->nullable()->after('storage_end_date');
            $table->string('rate_unit', 40)->nullable()->after('is_storage_ongoing');
            $table->boolean('requires_customs_bonded')->nullable()->after('rate_unit');
            $table->boolean('requires_racking')->nullable()->after('requires_customs_bonded');
            $table->boolean('requires_security')->nullable()->after('requires_racking');
            // What the warehouse must be able to do with the goods, separate from loading_methods.
            $table->json('handling_equipment')->nullable()->after('requires_security');
        });
    }

    public function down(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropColumn([
                'storage_type',
                'storage_start_date',
                'storage_end_date',
                'is_storage_ongoing',
                'rate_unit',
                'requires_customs_bonded',
                'requires_racking',
                'requires_security',
                'handling_equipment',
            ]);
        });
    }
};
