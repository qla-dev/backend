<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'fiscal_status')) {
            Schema::table('invoices', function (Blueprint $t): void {
                // Fiscal state of the existing outgoing invoice; Smart POS never creates a second invoice record.
                $t->string('fiscal_status', 20)->default('none');
                $t->unsignedBigInteger('fiscal_number')->nullable();
                $t->timestamp('fiscalised_at')->nullable();
                $t->string('fiscal_payment_method', 20)->nullable();
                $t->string('fiscal_device', 120)->nullable();
                $t->unsignedBigInteger('fiscal_refund_number')->nullable();
                $t->timestamp('fiscal_refunded_at')->nullable();
                $t->index(['company_id', 'fiscal_number'], 'invoices_company_fiscal_number_index');
            });
        }
        if (! Schema::hasTable('accounting_fiscal_operations')) {
            Schema::create('accounting_fiscal_operations', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->unsignedBigInteger('invoice_id')->nullable()->index();
                $t->string('operation', 30);
                $t->string('status', 12)->default('pending');
                $t->string('request_key', 100);
                $t->string('command', 80)->nullable();
                $t->json('payload')->nullable();
                $t->json('response')->nullable();
                $t->text('error')->nullable();
                $t->unsignedBigInteger('requested_by');
                $t->timestamps();
                $t->unique(['company_id', 'request_key'], 'accounting_fiscal_request_unique');
            });
        }
        if (! Schema::hasTable('accounting_pantheon_connectors')) {
            Schema::create('accounting_pantheon_connectors', function (Blueprint $t): void {
                $t->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
                $t->string('host');
                $t->unsignedInteger('port')->default(1433);
                $t->string('database', 128);
                $t->string('schema', 64)->default('dbo');
                // Connector licence = Pantheon SQL login; the password is stored encrypted.
                $t->string('username', 128);
                $t->text('password');
                $t->boolean('allow_write')->default(false);
                $t->unsignedInteger('clerk_id')->default(0);
                $t->string('outgoing_doc_type', 4)->default('4200');
                $t->string('incoming_doc_type', 4)->default('4300');
                $t->string('journal_doc_type', 4)->default('4700');
                $t->timestamp('last_tested_at')->nullable();
                $t->string('last_test_status', 12)->nullable();
                $t->text('last_test_error')->nullable();
                $t->unsignedBigInteger('updated_by');
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('accounting_pantheon_links')) {
            Schema::create('accounting_pantheon_links', function (Blueprint $t): void {
                $t->id();
                $t->foreignId('company_id')->constrained()->restrictOnDelete();
                $t->string('entity_type', 20);
                $t->unsignedBigInteger('local_id');
                $t->string('pantheon_key', 30);
                $t->string('direction', 8);
                $t->unsignedBigInteger('synced_by');
                $t->timestamps();
                $t->unique(['company_id', 'entity_type', 'local_id'], 'accounting_pantheon_local_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Fiscal and integration records must be preserved. This migration has no destructive rollback.');
    }
};
