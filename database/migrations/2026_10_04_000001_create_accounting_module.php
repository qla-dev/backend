<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('invoices', 'accounting_managed')) {
            Schema::table('invoices', function (Blueprint $t): void {
                $t->unsignedBigInteger('customer_user_id')->nullable()->change();
                $t->date('issued_at')->nullable()->change();
                $t->date('due_at')->nullable()->change();
                $t->boolean('accounting_managed')->default(false)->index();
                $t->string('direction', 12)->default('outgoing');
                $t->string('partner_name')->nullable();
                $t->unsignedBigInteger('partner_id')->nullable()->index();
                $t->string('partner_tax_number', 100)->nullable();
                $t->string('supplier_number', 100)->nullable();
                $t->string('approval_status', 20)->default('draft');
                $t->string('issuance_status', 20)->default('draft');
                $t->date('event_date')->nullable();
                $t->date('tax_date')->nullable();
                $t->date('posting_date')->nullable();
                $t->char('base_currency', 3)->default('BAM');
                $t->decimal('exchange_rate', 20, 8)->nullable();
                $t->date('exchange_date')->nullable();
                $t->string('exchange_source')->nullable();
                $t->text('exchange_reason')->nullable();
                $t->unsignedBigInteger('approved_by')->nullable();
                $t->unsignedBigInteger('corrects_invoice_id')->nullable();
                $t->unsignedInteger('revision')->default(1);
                $t->json('issued_snapshot')->nullable();
            });
        }
        if (! Schema::hasColumn('invoice_items', 'tax_amount')) {
            Schema::table('invoice_items', function (Blueprint $t): void {
                $t->decimal('quantity', 18, 4)->default(1)->change();
                $t->decimal('unit_price', 18, 4)->change();
                $t->decimal('tax_amount', 14, 2)->nullable();
                $t->unsignedBigInteger('tax_rule_id')->nullable();
                $t->unsignedBigInteger('account_id')->nullable();
                $t->string('tax_treatment', 60)->default('unknown');
                $t->decimal('tax_rate', 8, 4)->nullable();
                $t->decimal('deductible_percent', 7, 4)->nullable();
                $t->unsignedBigInteger('workspace_id')->nullable();
            });
        }
        $this->create('accounting_permissions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->constrained()->restrictOnDelete();
            $t->json('abilities');
            $t->unsignedBigInteger('granted_by');
            $t->timestamps();
            $t->unique(['company_id', 'user_id']);
        });
        $this->create('accounting_settings', function (Blueprint $t): void {
            $t->foreignId('company_id')->primary()->constrained()->restrictOnDelete();
            $t->string('jurisdiction', 10);
            $t->char('base_currency', 3)->default('BAM');
            $t->string('invoice_prefix', 40)->default('INV');
            $t->unsignedBigInteger('next_invoice_number')->default(1);
            $t->timestamps();
        });
        $this->create('accounting_partners', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('tax_number', 100)->nullable();
            $t->string('vat_number', 100)->nullable();
            $t->string('address')->nullable();
            $t->char('country_code', 2);
            $t->string('email')->nullable();
            $t->unsignedBigInteger('customer_id')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'tax_number']);
        });
        $this->create('accounting_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('code', 30);
            $t->string('name');
            $t->string('kind', 20);
            $t->boolean('active')->default(true);
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'code']);
        });
        $this->create('accounting_periods', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('name', 80);
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('status', 12)->default('open');
            $t->unsignedBigInteger('locked_by')->nullable();
            $t->timestamp('locked_at')->nullable();
            $t->timestamps();
            $t->index(['company_id', 'starts_on', 'ends_on']);
        });
        $this->create('accounting_tax_rules', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->string('jurisdiction', 10);
            $t->string('tax_type', 20)->default('vat');
            $t->string('treatment', 60);
            $t->text('conditions');
            $t->decimal('rate', 8, 4)->nullable();
            $t->decimal('deductible_percent', 7, 4)->nullable();
            $t->date('effective_from');
            $t->date('effective_until')->nullable();
            $t->date('applies_from');
            $t->text('source_url');
            $t->string('article', 120);
            $t->string('version', 120);
            $t->string('verification_status', 20)->default('unverified');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
        });
        $this->create('accounting_entries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('period_id')->constrained('accounting_periods')->restrictOnDelete();
            $t->string('event_key', 150);
            $t->date('posting_date');
            $t->text('description');
            $t->unsignedBigInteger('invoice_id')->nullable();
            $t->unsignedBigInteger('reverses_entry_id')->nullable()->unique();
            $t->unsignedBigInteger('posted_by');
            $t->timestamps();
            $t->unique(['company_id', 'event_key']);
        });
        $this->create('accounting_entry_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('entry_id')->constrained('accounting_entries')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('accounting_accounts')->restrictOnDelete();
            $t->decimal('debit', 16, 2)->default(0);
            $t->decimal('credit', 16, 2)->default(0);
            $t->unsignedBigInteger('tax_rule_id')->nullable();
            $t->unsignedBigInteger('invoice_item_id')->nullable();
        });
        $this->create('accounting_bank_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->string('reference', 120);
            $t->string('bank_account', 100);
            $t->string('partner_name');
            $t->foreignId('partner_id')->constrained('accounting_partners')->restrictOnDelete();
            $t->string('direction', 12);
            $t->date('transaction_date');
            $t->decimal('amount', 16, 2);
            $t->char('currency', 3);
            $t->decimal('exchange_rate', 20, 8);
            $t->string('exchange_source');
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
            $t->unique(['company_id', 'bank_account', 'reference'], 'accounting_bank_reference_unique');
        });
        $this->create('accounting_payment_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_transaction_id')->constrained('accounting_bank_transactions')->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->decimal('amount', 16, 2);
            $t->string('request_key', 100);
            $t->unsignedBigInteger('confirmed_by');
            $t->timestamps();
            $t->unique(['company_id', 'request_key']);
        });
        $this->create('accounting_advances', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('bank_transaction_id')->constrained('accounting_bank_transactions')->restrictOnDelete()->unique();
            $t->string('partner_name');
            $t->decimal('amount', 16, 2);
            $t->unsignedBigInteger('control_account_id');
            $t->decimal('settled_amount', 16, 2)->default(0);
            $t->unsignedBigInteger('created_by');
            $t->timestamps();
        });
        $this->create('accounting_cost_allocations', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_item_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('workspace_id')->nullable();
            $t->decimal('amount', 16, 2);
            $t->unsignedBigInteger('estimate_id')->nullable();
            $t->timestamps();
        });
        $this->create('accounting_cost_estimates', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('workspace_id');
            $t->string('description');
            $t->decimal('amount', 16, 2);
            $t->char('currency', 3);
            $t->timestamps();
        });
        $this->create('accounting_invoice_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('document_id')->constrained()->restrictOnDelete();
            $t->unique(['invoice_id', 'document_id']);
        });
        $this->create('accounting_audit_events', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('user_id');
            $t->string('entity_type', 40);
            $t->unsignedBigInteger('entity_id');
            $t->string('action', 60);
            $t->json('details')->nullable();
            $t->timestamp('created_at');
            $t->index(['company_id', 'entity_type', 'entity_id']);
        });
        $this->create('accounting_issued_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete()->unique();
            $t->foreignId('document_id')->constrained()->restrictOnDelete()->unique();
            $t->longText('html');
            $t->timestamps();
        });
        $this->create('accounting_invoice_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->string('recipient');
            $t->json('document_ids');
            $t->string('status', 12)->default('pending');
            $t->unsignedBigInteger('requested_by');
            $t->string('request_key', 100);
            $t->text('error')->nullable();
            $t->timestamp('sent_at')->nullable();
            $t->timestamps();
            $t->unique(['company_id', 'request_key']);
        });
    }

    private function create(string $table, Closure $definition): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $definition);
        }
        if ($table === 'accounting_bank_transactions' && ! Schema::hasIndex($table, ['company_id', 'bank_account', 'reference'], 'unique')) {
            Schema::table($table, function (Blueprint $t): void {
                $t->unique(['company_id', 'bank_account', 'reference'], 'accounting_bank_reference_unique');
            });
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Accounting records must be preserved. This migration has no destructive rollback.');
    }
};
