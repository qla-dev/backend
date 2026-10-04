<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Push state for the remaining PANTHEON syncs (see docs/agents/pantheon-integration.md):
 * SmartFreight CRM offers/orders -> tHE_Order/tHE_OrderItem, and work-order time/events ->
 * tHF_WOExItemWork/tHF_WOExRegOper. SmartFreight stays the master; nothing here writes PANTHEON.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('crm_documents', 'revision')) {
            Schema::table('crm_documents', function (Blueprint $t): void {
                // Every content change bumps revision; the sync pushes when PANTHEON has an older one.
                $t->unsignedInteger('revision')->default(1);
                $t->unsignedInteger('pantheon_revision')->nullable();
                $t->timestamp('pantheon_pushed_at')->nullable();
            });
        }
        if (! Schema::hasColumn('crm_document_items', 'vat_code')) {
            Schema::table('crm_document_items', function (Blueprint $t): void {
                // PANTHEON tHE_SetTax.acVATCode: the rate alone is ambiguous (0 % = export, exempt, ...).
                $t->string('vat_code', 2)->nullable();
            });
        }
        if (! Schema::hasColumn('accounting_pantheon_connectors', 'crm_push_enabled')) {
            Schema::table('accounting_pantheon_connectors', function (Blueprint $t): void {
                $t->boolean('crm_push_enabled')->default(false);
                $t->char('crm_push_doc_type', 4)->nullable(); // e.g. 0110 (Predračun/ponuda kupcu)
                $t->date('crm_push_from')->nullable();
            });
        }
        if (! Schema::hasColumn('ops_pantheon_sync', 'worker_map')) {
            Schema::table('ops_pantheon_sync', function (Blueprint $t): void {
                // SmartFreight user id -> PANTHEON worker (tHR_Prsn.acWorker); unmapped users fall back to default_worker.
                $t->json('worker_map')->nullable();
                $t->string('default_worker', 30)->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Integration state must be preserved. This migration has no destructive rollback.');
    }
};
