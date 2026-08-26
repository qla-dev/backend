<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Sea's "Container types" picker (multiple container type + quantity rows) and the detail fields
// opened by the DG/IMO, OOG, and B/L-type parts of the sea cargo/terms redesign. Applied to both
// `loads` and `load_drafts` since PostLoadModal's buildLoadFieldsPayload() feeds both.
return new class extends Migration
{
    public function up(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->json('container_selections')->nullable()->after('body_types');
                $blueprint->string('bl_type', 30)->nullable()->after('container_selections');
                $blueprint->string('dg_un_number', 20)->nullable()->after('bl_type');
                $blueprint->string('dg_imo_class', 20)->nullable()->after('dg_un_number');
                $blueprint->string('dg_packing_group', 10)->nullable()->after('dg_imo_class');
                $blueprint->string('dg_proper_shipping_name', 255)->nullable()->after('dg_packing_group');
                $blueprint->string('oog_in_gauge', 20)->nullable()->after('dg_proper_shipping_name');
                $blueprint->decimal('oog_length_m', 8, 3)->nullable()->after('oog_in_gauge');
                $blueprint->decimal('oog_width_m', 8, 3)->nullable()->after('oog_length_m');
                $blueprint->decimal('oog_height_m', 8, 3)->nullable()->after('oog_width_m');
                $blueprint->decimal('oog_weight_kg', 10, 2)->nullable()->after('oog_height_m');
            });
        }
    }

    public function down(): void
    {
        foreach (['loads', 'load_drafts'] as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn([
                    'container_selections', 'bl_type', 'dg_un_number', 'dg_imo_class', 'dg_packing_group',
                    'dg_proper_shipping_name', 'oog_in_gauge', 'oog_length_m', 'oog_width_m', 'oog_height_m', 'oog_weight_kg',
                ]);
            });
        }
    }
};
