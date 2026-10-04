<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('accounting_pantheon_connectors', 'sales_doc_types')) {
            Schema::table('accounting_pantheon_connectors', function (Blueprint $t): void {
                // PANTHEON tHE_Order document types that are customer offers/orders (Trendy: 0100, 0110, 0120).
                $t->string('sales_doc_types', 120)->default('0100,0110,0120');
                // tHE_Move document types that are delivery notes; other linked 3xxx moves count as invoices.
                $t->string('delivery_doc_types', 120)->default('3500');
                $t->timestamp('last_crm_sync_at')->nullable();
            });
        }
        if (! Schema::hasTable('crm_documents')) {
            Schema::create('crm_documents', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('partner_id')->nullable()->index();
                $t->unsignedBigInteger('customer_id')->nullable()->index();
                $t->string('source', 12);
                $t->string('pantheon_key', 13)->nullable();
                $t->string('number', 40)->nullable();
                $t->string('doc_type', 4)->nullable();
                $t->string('pantheon_status', 1)->nullable();
                $t->string('stage', 20);
                $t->string('customer_name');
                $t->string('customer_key', 30)->nullable();
                $t->string('customer_tax_number', 30)->nullable();
                $t->string('contact_name')->nullable();
                $t->string('title')->nullable();
                $t->date('issued_on')->nullable();
                $t->date('valid_until')->nullable();
                $t->date('delivery_deadline')->nullable();
                $t->char('currency', 3)->default('BAM');
                $t->decimal('net_amount', 16, 2)->default(0);
                $t->decimal('vat_amount', 16, 2)->default(0);
                $t->decimal('total_amount', 16, 2)->default(0);
                $t->decimal('ordered_quantity', 18, 4)->default(0);
                $t->decimal('delivered_quantity', 18, 4)->default(0);
                $t->json('linked_documents')->nullable();
                $t->text('note')->nullable();
                // SmartFreight-only CRM fields; a PANTHEON sync never overwrites them.
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->date('next_follow_up_on')->nullable();
                $t->string('lost_reason', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('synced_at')->nullable();
                $t->timestamps();
                $t->unique(['company_id', 'pantheon_key'], 'crm_documents_pantheon_unique');
                $t->index(['company_id', 'stage', 'issued_on'], 'crm_documents_stage_index');
            });
        }
        if (! Schema::hasTable('crm_document_items')) {
            Schema::create('crm_document_items', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('crm_document_id')->constrained()->restrictOnDelete();
                $t->unsignedInteger('line_no');
                $t->string('item_code', 30)->nullable();
                $t->string('name');
                $t->decimal('quantity', 18, 4)->default(0);
                $t->decimal('delivered_quantity', 18, 4)->default(0);
                $t->string('unit', 6)->nullable();
                $t->decimal('unit_price', 18, 4)->default(0);
                $t->decimal('discount_percent', 8, 4)->default(0);
                $t->decimal('vat_percent', 8, 4)->nullable();
                $t->date('delivery_deadline')->nullable();
                $t->unique(['crm_document_id', 'line_no']);
            });
        }
        if (! Schema::hasTable('crm_contacts')) {
            Schema::create('crm_contacts', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('partner_id')->nullable()->index();
                $t->string('customer_key', 30)->nullable();
                $t->unsignedInteger('pantheon_no')->nullable();
                $t->string('name');
                $t->string('function')->nullable();
                $t->string('email')->nullable();
                $t->string('phone', 60)->nullable();
                $t->boolean('active')->default(true);
                $t->string('source', 12);
                $t->timestamps();
                $t->unique(['company_id', 'customer_key', 'pantheon_no'], 'crm_contacts_pantheon_unique');
            });
        }
        if (! Schema::hasTable('crm_follow_ups')) {
            Schema::create('crm_follow_ups', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('crm_document_id')->nullable()->index();
                $t->unsignedBigInteger('partner_id')->nullable();
                $t->date('due_on');
                $t->string('note', 1000);
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->timestamp('done_at')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->index(['company_id', 'done_at', 'due_on']);
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('CRM records must be preserved. This migration has no destructive rollback.');
    }
};
