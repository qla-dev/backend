<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A storage draft now records which of the two things it is.
//
// "Store goods" in LenaAI splits in two: goods coming into a warehouse the account operates itself
// (which becomes an inbound dock movement once published), and a storage request published to the
// exchange for someone else's warehouse to bid on. Both are the same form and the same draft table,
// so the branch has to be written down rather than guessed at publish time.
//
// `warehouse_id` is only meaningful for the first: it is the facility the goods are coming into.
// Nulled rather than cascaded, so removing a facility leaves the draft rather than deleting it.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            // 'own' | 'exchange'. Null on every draft that is not a storage request.
            $table->string('storage_target', 20)->nullable()->after('storage_type');
            $table->foreignId('warehouse_id')->nullable()->after('storage_target')
                ->constrained('warehouses')->nullOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('load_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('warehouse_id');
            $table->dropColumn('storage_target');
        });
    }
};
