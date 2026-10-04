<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Product/service catalogue (e.g. "Prevoz"): one list used by CRM offers, service templates and POS.
 * SmartFreight is the master; the PANTHEON sync pulls tHE_SetItem of the configured item sets (with
 * stock from tHE_Stock) and pushes products created here. Nothing in this migration writes PANTHEON.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalog_products')) {
            Schema::create('catalog_products', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->string('code', 16); // = PANTHEON tHE_SetItem.acIdent
                $t->string('name', 80);
                $t->string('kind', 10)->default('service'); // service | product
                $t->string('unit', 3)->default('KOM');
                $t->string('item_set', 3)->nullable(); // PANTHEON acSetOfItem, e.g. USL
                $t->decimal('sale_price', 18, 4)->nullable();
                $t->char('currency', 3)->default('BAM');
                $t->decimal('vat_percent', 8, 4)->nullable();
                $t->string('vat_code', 2)->nullable(); // PANTHEON acVATCode
                $t->decimal('stock', 18, 4)->nullable(); // read from PANTHEON tHE_Stock, all warehouses
                $t->boolean('active')->default(true);
                $t->string('source', 12)->default('smartfreight');
                $t->unsignedInteger('revision')->default(1);
                $t->unsignedInteger('pantheon_revision')->nullable();
                $t->timestamp('synced_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'code']);
                $t->index(['company_id', 'active', 'name']);
            });
        }
        if (! Schema::hasColumn('accounting_pantheon_connectors', 'catalog_item_sets')) {
            Schema::table('accounting_pantheon_connectors', function (Blueprint $t): void {
                $t->string('catalog_item_sets', 120)->default('USL,OPR');
                $t->boolean('catalog_push_enabled')->default(false);
                $t->string('catalog_push_item_set', 3)->nullable();
                $t->timestamp('last_catalog_sync_at')->nullable();
            });
        }
        foreach (['ops_service_template_items', 'ops_order_items'] as $table) {
            if (! Schema::hasColumn($table, 'vat_percent')) {
                Schema::table($table, function (Blueprint $t): void {
                    $t->unsignedBigInteger('product_id')->nullable();
                    $t->decimal('vat_percent', 8, 4)->nullable();
                    $t->string('vat_code', 2)->nullable();
                });
            }
        }
        if (! Schema::hasColumn('crm_document_items', 'product_id')) {
            Schema::table('crm_document_items', function (Blueprint $t): void {
                $t->unsignedBigInteger('product_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Catalogue records must be preserved. This migration has no destructive rollback.');
    }
};
