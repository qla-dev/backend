<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_pantheon_connectors', 'sync_enabled')) {
            Schema::table('accounting_pantheon_connectors', function (Blueprint $t): void {
                // Continuous sync: PANTHEON is the master for accounts/partners, the app pushes posted entries.
                $t->boolean('sync_enabled')->default(false);
                $t->unsignedSmallInteger('sync_interval_minutes')->default(15);
                // Entries posted before this date are never pushed automatically.
                $t->date('push_entries_from')->nullable();
                // Country for subjects without a VAT prefix; null keeps them pending.
                $t->char('default_country_code', 2)->nullable();
                // Kind choices for accounts whose class gives no kind (7–9), keyed by account code.
                $t->json('account_kinds')->nullable();
                $t->timestamp('last_synced_at')->nullable();
                $t->string('last_sync_status', 12)->nullable();
                $t->text('last_sync_error')->nullable();
                $t->json('last_sync_summary')->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Integration state must be preserved. This migration has no destructive rollback.');
    }
};
