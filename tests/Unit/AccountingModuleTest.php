<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Models\User;
use App\Services\Accounting\AccountingAccess;
use App\Services\Accounting\AccountingInvoices;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\AccountingPayments;
use App\Services\Accounting\Decimal;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AccountingModuleTest extends TestCase
{
    private $app;

    private AccountingLedger $ledger;

    private AccountingInvoices $invoices;

    private AccountingPayments $payments;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('Explicit SQLite :memory: process settings required.');
        }
        $this->app = require __DIR__.'/../../bootstrap/app.php';
        $this->app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections', ['sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        });
        $this->app->make(Kernel::class)->bootstrap();
        // Read-only effective-connection verification BEFORE any schema or record write.
        $connection = DB::connection();
        self::assertSame('sqlite', $connection->getConfig('driver'));
        self::assertNull($connection->getConfig('host'));
        self::assertNull($connection->getConfig('port'));
        self::assertSame(':memory:', $connection->getConfig('database'));
        self::assertSame('', $connection->select('PRAGMA database_list')[0]->file);
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
        });
        Schema::create('companies', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('owner_user_id');
            $t->string('name');
        });
        Schema::create('company_user', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('user_id');
            $t->string('status');
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->string('name');
            $t->string('mime_type')->nullable();
            $t->unsignedBigInteger('load_id')->nullable();
            $t->string('type');
            $t->string('reference')->nullable();
            $t->string('path');
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->timestamps();
        });
        Schema::create('loads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('company_id');
        });
        Schema::create('shipment_workspace', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('provider_company_id');
            $t->unsignedBigInteger('load_id');
        });
        Schema::create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('customer_user_id');
            $t->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('load_id')->nullable();
            $t->unsignedBigInteger('issued_by_user_id');
            $t->string('number')->unique();
            $t->string('status')->default('draft');
            $t->char('currency', 3)->default('EUR');
            foreach (['subtotal', 'tax', 'total'] as $c) {
                $t->decimal($c, 14, 2)->default(0);
            }
            $t->date('issued_at');
            $t->date('due_at');
            $t->timestamp('paid_at')->nullable();
            $t->timestamps();
        });
        Schema::create('invoice_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('load_id')->nullable();
            $t->string('description');
            $t->decimal('quantity', 10, 2)->default(1);
            $t->decimal('unit_price', 14, 2);
            $t->decimal('total', 14, 2);
            $t->timestamps();
        });
        (require __DIR__.'/../../database/migrations/2026_10_04_000001_create_accounting_module.php')->up();
        DB::table('users')->insert([['id' => 1, 'name' => 'Accountant'], ['id' => 2, 'name' => 'Other company']]);
        DB::table('companies')->insert([['id' => 1, 'owner_user_id' => 1, 'name' => 'Company A'], ['id' => 2, 'owner_user_id' => 2, 'name' => 'Company B']]);
        DB::table('accounting_settings')->insert([['company_id' => 1, 'jurisdiction' => 'FBiH', 'base_currency' => 'BAM'], ['company_id' => 2, 'jurisdiction' => 'RS', 'base_currency' => 'BAM']]);
        DB::table('accounting_partners')->insert(['id' => 1, 'company_id' => 1, 'name' => 'Carrier', 'country_code' => 'BA']);
        DB::table('accounting_periods')->insert(['id' => 1, 'company_id' => 1, 'name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        foreach (['expense', 'liability', 'asset', 'income', 'expense'] as $n => $kind) {
            DB::table('accounting_accounts')->insert(['id' => $n + 1, 'company_id' => 1, 'code' => (string) ($n + 1), 'name' => $kind, 'kind' => $kind, 'approved_by' => 1]);
        }
        DB::table('accounting_accounts')->insert(['id' => 99, 'company_id' => 2, 'code' => 'OTHER', 'name' => 'Other', 'kind' => 'asset', 'approved_by' => 2]);
        DB::table('accounting_tax_rules')->insert(['id' => 1, 'company_id' => 1, 'name' => 'Synthetic test rule — no legal assertion', 'jurisdiction' => 'FBiH', 'tax_type' => 'vat',
            'treatment' => 'test_zero', 'conditions' => 'Synthetic fixture', 'rate' => '0', 'deductible_percent' => '0', 'effective_from' => '2026-01-01', 'applies_from' => '2026-01-01',
            'source_url' => 'https://example.test/rule', 'article' => 'test', 'version' => 'test-v1', 'verification_status' => 'approved', 'approved_by' => 1]);
        DB::table('loads')->insert([['id' => 1, 'company_id' => 1], ['id' => 2, 'company_id' => 1]]);
        DB::table('shipment_workspace')->insert([['id' => 1, 'provider_company_id' => 1, 'load_id' => 1], ['id' => 2, 'provider_company_id' => 1, 'load_id' => 2]]);
        $this->ledger = new AccountingLedger;
        $this->invoices = new AccountingInvoices($this->ledger);
        $this->payments = new AccountingPayments($this->ledger);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            DB::disconnect();
            $this->app->flush();
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    private function draft(array $overrides = []): Invoice
    {
        return $this->invoices->save(1, 1, array_replace([
            'direction' => 'incoming', 'partner_id' => 1, 'partner_name' => 'Carrier', 'supplier_number' => 'SUP-001', 'currency' => 'BAM',
            'issued_at' => '2026-10-04', 'due_at' => '2026-10-20', 'event_date' => '2026-10-04', 'tax_date' => '2026-10-04', 'posting_date' => '2026-10-04',
            'items' => [['description' => 'Transport', 'quantity' => '1', 'unit_price' => '1000', 'tax_rule_id' => 1, 'account_id' => 1]],
        ], $overrides));
    }

    private function posted(array $overrides = []): Invoice
    {
        $i = $this->draft($overrides);
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
        $this->invoices->transition(1, 1, $i->id, 'approve', []);
        if ($i->direction === 'outgoing') {
            $this->invoices->transition(1, 1, $i->id, 'issue', []);
        }

        return $this->invoices->transition(1, 1, $i->id, 'post', ['control_account_id' => 2]);
    }

    private function bank(string $amount = '1000', string $currency = 'BAM', string $rate = '1'): int
    {
        return DB::table('accounting_bank_transactions')->insertGetId(['company_id' => 1, 'reference' => 'BANK-'.uniqid(), 'bank_account' => 'TEST',
            'partner_id' => 1, 'partner_name' => 'Carrier', 'direction' => 'outgoing', 'transaction_date' => '2026-10-05', 'amount' => $amount, 'currency' => $currency,
            'exchange_rate' => $rate, 'exchange_source' => 'test', 'created_by' => 1]);
    }

    public function test_incomplete_manual_draft_is_saved_without_supplier_dates_or_items(): void
    {
        $i = $this->invoices->save(1, 1, ['direction' => 'incoming']);
        self::assertNull($i->customer_user_id);
        self::assertNull($i->issued_at);
        self::assertSame('draft', $i->approval_status);
        self::assertCount(0, $i->items);
    }

    public function test_unknown_tax_cannot_be_approved(): void
    {
        $i = $this->draft(['items' => [['description' => 'Unknown', 'quantity' => '1', 'unit_price' => '50', 'account_id' => 1]]]);
        self::assertNull($i->items[0]->tax_amount);
        self::assertSame('unknown', $i->items[0]->tax_treatment);
        $this->expectException(ValidationException::class);
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
    }

    public function test_posting_is_balanced_and_retry_creates_only_one_entry(): void
    {
        $i = $this->posted();
        $this->invoices->transition(1, 1, $i->id, 'post', ['control_account_id' => 2]);
        self::assertSame(1, DB::table('accounting_entries')->count());
        self::assertSame('1000.00', Decimal::sum(DB::table('accounting_entry_lines')->pluck('debit')));
        self::assertSame('1000.00', Decimal::sum(DB::table('accounting_entry_lines')->pluck('credit')));
    }

    public function test_locked_period_refuses_invoice_posting_without_partial_records(): void
    {
        $i = $this->draft();
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
        $this->invoices->transition(1, 1, $i->id, 'approve', []);
        DB::table('accounting_periods')->where('id', 1)->update(['status' => 'locked']);
        try {
            $this->invoices->transition(1, 1, $i->id, 'post', ['control_account_id' => 2]);
            self::fail('Expected locked-period failure.');
        } catch (ValidationException $e) {
            self::assertSame(0, DB::table('accounting_entries')->count());
            self::assertSame(0, DB::table('accounting_entry_lines')->count());
        }
    }

    public function test_cross_company_account_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->ledger->post(1, 1, ['event_key' => 'manual:test', 'posting_date' => '2026-10-04', 'description' => 'Test', 'lines' => [
            ['account_id' => 99, 'debit' => '10', 'credit' => '0'], ['account_id' => 2, 'debit' => '0', 'credit' => '10']]]);
    }

    public function test_owner_has_no_implicit_accounting_abilities(): void
    {
        $access = new AccountingAccess;
        self::assertSame([], $access->abilities(User::find(1), 1));
        $this->expectException(HttpException::class);
        $access->authorize(User::find(1), 1, 'post');
    }

    public function test_partial_payment_and_retry_are_atomic_and_do_not_duplicate(): void
    {
        $i = $this->posted();
        $b = $this->bank('500');
        $data = ['invoice_id' => $i->id, 'bank_transaction_id' => $b, 'amount' => '500', 'request_key' => 'payment-1', 'bank_account_id' => 3];
        $this->payments->allocate(1, 1, $data);
        $this->payments->allocate(1, 1, $data);
        self::assertSame('500.00', $this->payments->allocated('invoice_id', $i->id));
        self::assertSame(1, DB::table('accounting_payment_allocations')->count());
        self::assertSame(2, DB::table('accounting_entries')->count());
        self::assertSame('approved', $i->fresh()->approval_status);
    }

    public function test_payment_cannot_exceed_bank_balance(): void
    {
        $i = $this->posted();
        $b = $this->bank('500');
        $this->expectException(ValidationException::class);
        $this->payments->allocate(1, 1, ['invoice_id' => $i->id, 'bank_transaction_id' => $b, 'amount' => '501', 'request_key' => 'overflow', 'bank_account_id' => 3]);
    }

    public function test_failed_payment_journal_rolls_back_allocation(): void
    {
        $i = $this->posted();
        $b = $this->bank('500');
        DB::table('accounting_periods')->where('id', 1)->update(['status' => 'locked']);
        try {
            $this->payments->allocate(1, 1, ['invoice_id' => $i->id, 'bank_transaction_id' => $b, 'amount' => '500', 'request_key' => 'locked', 'bank_account_id' => 3]);
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame(0, DB::table('accounting_payment_allocations')->count());
            self::assertSame(1, DB::table('accounting_entries')->count());
        }
    }

    public function test_exchange_difference_uses_posted_control_value(): void
    {
        // Synthetic USD rates, not legal exchange-rate assertions.
        $i = $this->posted(['currency' => 'USD', 'exchange_rate' => '1.955830', 'exchange_date' => '2026-10-04', 'exchange_source' => 'synthetic', 'exchange_reason' => 'test']);
        $b = $this->bank('1000', 'USD', '1.950000');
        $this->payments->allocate(1, 1, ['invoice_id' => $i->id, 'bank_transaction_id' => $b, 'amount' => '1000', 'request_key' => 'fx', 'bank_account_id' => 3, 'fx_gain_account_id' => 4]);
        $last = DB::table('accounting_entries')->orderByDesc('id')->first();
        $lines = DB::table('accounting_entry_lines')->where('entry_id', $last->id)->get();
        self::assertSame('1955.83', Decimal::value($lines[0]->debit));
        self::assertSame('1950.00', Decimal::value($lines[1]->credit));
        self::assertSame('5.83', Decimal::value($lines[2]->credit));
    }

    public function test_two_job_cost_allocation_replaces_only_linked_estimate(): void
    {
        DB::table('accounting_cost_estimates')->insert(['id' => 1, 'company_id' => 1, 'workspace_id' => 1, 'description' => 'Transport estimate', 'amount' => '90', 'currency' => 'BAM']);
        $i = $this->draft(['items' => [['description' => 'Cost', 'quantity' => '1', 'unit_price' => '200', 'tax_rule_id' => 1, 'account_id' => 1,
            'allocations' => [['workspace_id' => 1, 'amount' => '100', 'estimate_id' => 1], ['workspace_id' => 2, 'amount' => '100']]]]]);
        $rows = DB::table('accounting_cost_allocations')->where('invoice_item_id', $i->items[0]->id)->get();
        self::assertCount(2, $rows);
        self::assertSame(1, $rows[0]->estimate_id);
        self::assertNull($rows[1]->estimate_id);
        self::assertSame('200.00', Decimal::sum($rows->pluck('amount')));
    }

    public function test_approved_invoice_content_cannot_be_changed(): void
    {
        $i = $this->posted();
        $this->expectException(ValidationException::class);
        $this->invoices->save(1, 1, ['revision' => $i->revision, 'partner_name' => 'Changed'], $i->id);
    }

    public function test_outgoing_number_is_assigned_only_at_issuance(): void
    {
        $i = $this->draft(['direction' => 'outgoing']);
        self::assertStringStartsWith('DRAFT-', $i->number);
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
        $this->invoices->transition(1, 1, $i->id, 'approve', []);
        $i = $this->invoices->transition(1, 1, $i->id, 'issue', []);
        self::assertSame('INV-1-000001', $i->number);
        self::assertSame('issued', $i->issuance_status);
    }

    public function test_manual_reversal_preserves_original_and_is_idempotent(): void
    {
        $e = $this->ledger->post(1, 1, ['event_key' => 'opening:one', 'posting_date' => '2026-10-04', 'description' => 'Opening', 'lines' => [
            ['account_id' => 1, 'debit' => '10', 'credit' => '0'], ['account_id' => 2, 'debit' => '0', 'credit' => '10']]]);
        $this->ledger->reverse(1, 1, $e->id, '2026-10-05', 'Correction');
        $this->ledger->reverse(1, 1, $e->id, '2026-10-05', 'Correction');
        self::assertSame(2, DB::table('accounting_entries')->count());
        self::assertSame('20.00', Decimal::sum(DB::table('accounting_entry_lines')->pluck('debit')));
    }

    public function test_decimal_precision_and_half_up_rounding(): void
    {
        self::assertSame('1.01', Decimal::round('1.005'));
        self::assertSame('-1.01', Decimal::round('-1.005'));
        self::assertSame('1955.83', Decimal::convert('1000', '1.95583000'));
        self::assertSame('0.30', Decimal::sum(['0.1', '0.2']));
    }

    public function test_corrective_invoice_preserves_source_and_reverses_posted_lines(): void
    {
        $source = $this->posted();
        $correction = $this->invoices->corrective(1, 1, $source->id, '2026-10-06', 'Supplier full credit');
        self::assertSame($source->id, $correction->corrects_invoice_id);
        $this->invoices->transition(1, 1, $correction->id, 'submit', []);
        $this->invoices->transition(1, 1, $correction->id, 'approve', []);
        $this->invoices->transition(1, 1, $correction->id, 'post', []);
        self::assertSame('reversed', $source->fresh()->posting_status);
        self::assertSame('0.00', $source->fresh()->remaining_amount);
        self::assertSame('1000.00', Decimal::value($source->fresh()->total));
        self::assertSame(2, DB::table('accounting_entries')->count());
        $control = DB::table('accounting_entry_lines')->where('account_id', 2)->get();
        self::assertSame(Decimal::sum($control->pluck('debit')), Decimal::sum($control->pluck('credit')));
    }

    public function test_full_corrective_invoice_cannot_be_changed_into_different_amounts(): void
    {
        $source = $this->posted();
        $correction = $this->invoices->corrective(1, 1, $source->id, '2026-10-06', 'Full credit');
        $this->expectException(ValidationException::class);
        $this->invoices->save(1, 1, ['revision' => 1, 'items' => []], $correction->id);
    }

    public function test_tax_calculation_uses_approved_rule_and_keeps_version_reference(): void
    {
        DB::table('accounting_tax_rules')->where('id', 1)->update(['rate' => '10.0000', 'deductible_percent' => '50.0000']);
        // Synthetic rate for arithmetic verification; this is not a BiH tax rule.
        $i = $this->draft(['items' => [['description' => 'Synthetic taxable expense', 'quantity' => '2.5000', 'unit_price' => '40.0000', 'tax_rule_id' => 1, 'account_id' => 1]]]);
        self::assertSame('100.00', Decimal::value($i->subtotal));
        self::assertSame('10.00', Decimal::value($i->tax));
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
        $this->invoices->transition(1, 1, $i->id, 'approve', []);
        $this->invoices->transition(1, 1, $i->id, 'post', ['control_account_id' => 2, 'vat_account_id' => 3]);
        $lines = DB::table('accounting_entry_lines')->orderBy('id')->get();
        self::assertSame('105.00', Decimal::value($lines[0]->debit));
        self::assertSame('5.00', Decimal::value($lines[1]->debit));
        self::assertSame('110.00', Decimal::value($lines[2]->credit));
        self::assertSame(1, $lines[0]->tax_rule_id);
    }

    public function test_unbalanced_journal_is_rejected_without_records(): void
    {
        try {
            $this->ledger->post(1, 1, ['event_key' => 'manual:unbalanced', 'posting_date' => '2026-10-04', 'description' => 'Test', 'lines' => [
                ['account_id' => 1, 'debit' => '10', 'credit' => '0'], ['account_id' => 2, 'debit' => '0', 'credit' => '9']]]);
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame(0, DB::table('accounting_entries')->count());
        }
    }

    public function test_database_unique_constraint_rejects_a_competing_event_insert(): void
    {
        $i = $this->posted();
        $entry = (array) DB::table('accounting_entries')->first();
        unset($entry['id']);
        $this->expectException(QueryException::class);
        DB::table('accounting_entries')->insert($entry);
    }

    public function test_stale_draft_revision_is_refused(): void
    {
        $i = $this->draft();
        $this->invoices->save(1, 1, ['revision' => 1, 'supplier_number' => 'REV-2'], $i->id);
        $this->expectException(ValidationException::class);
        $this->invoices->save(1, 1, ['revision' => 1, 'supplier_number' => 'STALE'], $i->id);
    }

    public function test_expired_rule_blocks_submission(): void
    {
        DB::table('accounting_tax_rules')->where('id', 1)->update(['effective_until' => '2026-09-30']);
        $i = $this->draft();
        $this->expectException(ValidationException::class);
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
    }

    public function test_unapproved_rule_blocks_submission_even_if_ai_selected_it(): void
    {
        DB::table('accounting_tax_rules')->where('id', 1)->update(['verification_status' => 'unverified', 'approved_by' => null]);
        $i = $this->draft();
        $this->expectException(ValidationException::class);
        $this->invoices->transition(1, 1, $i->id, 'submit', []);
    }

    public function test_split_payments_clear_original_control_with_rounding_remainder(): void
    {
        $i = $this->posted(['currency' => 'USD', 'exchange_rate' => '1.23456789', 'exchange_date' => '2026-10-04', 'exchange_source' => 'synthetic', 'exchange_reason' => 'test']);
        foreach (['333.33', '333.33', '333.34'] as $n => $amount) {
            $this->payments->allocate(1, 1, ['invoice_id' => $i->id, 'bank_transaction_id' => $this->bank($amount, 'USD', '1.23456789'), 'amount' => $amount,
                'request_key' => 'split-'.$n, 'bank_account_id' => 3, 'fx_gain_account_id' => 4, 'fx_loss_account_id' => 5]);
        }
        self::assertSame('paid', $i->fresh()->payment_status);
        self::assertSame('0.00', $i->fresh()->remaining_amount);
        $control = DB::table('accounting_entry_lines')->where('account_id', 2)->get();
        self::assertSame(Decimal::sum($control->pluck('debit')), Decimal::sum($control->pluck('credit')));
    }

    public function test_advances_are_settled_against_advance_control_without_double_bank_posting(): void
    {
        $i = $this->posted();
        $bankId = $this->bank('500');
        $advance = DB::table('accounting_advances')->insertGetId(['company_id' => 1, 'bank_transaction_id' => $bankId,
            'partner_name' => 'Carrier', 'amount' => '500', 'control_account_id' => 5, 'created_by' => 1]);
        $this->ledger->post(1, 1, ['event_key' => 'advance:'.$advance, 'posting_date' => '2026-10-05', 'description' => 'Advance', 'lines' => [
            ['account_id' => 5, 'debit' => '500', 'credit' => '0'], ['account_id' => 3, 'debit' => '0', 'credit' => '500']]]);
        $this->payments->allocate(1, 1, ['invoice_id' => $i->id, 'bank_transaction_id' => $bankId, 'amount' => '500', 'request_key' => 'advance-settlement', 'bank_account_id' => 3]);
        self::assertSame('500.00', Decimal::sum(DB::table('accounting_entry_lines')->where('account_id', 3)->pluck('credit')));
        self::assertSame('500.00', Decimal::value(DB::table('accounting_advances')->where('id', $advance)->value('settled_amount')));
    }

    public function test_issued_document_uses_user_company_and_keeps_immutable_snapshot(): void
    {
        $i = $this->posted(['direction' => 'outgoing']);
        $snapshot = $i->issued_snapshot;
        self::assertSame('Company A', $snapshot['seller']['name']);
        self::assertSame('Carrier', $snapshot['buyer']['name']);
        DB::table('companies')->where('id', 1)->update(['name' => 'Renamed company']);
        self::assertSame('Company A', $i->fresh()->issued_snapshot['seller']['name']);
        self::assertSame(1, DB::table('accounting_issued_documents')->count());
        self::assertSame(1, DB::table('accounting_invoice_documents')->count());
    }

    public function test_duplicate_supplier_number_is_a_warning_not_a_posting_block(): void
    {
        $a = $this->posted();
        $b = $this->posted();
        self::assertNotSame($a->id, $b->id);
        self::assertSame(2, DB::table('accounting_entries')->count());
    }

    public function test_outgoing_invoice_cannot_be_issued_twice_for_same_job(): void
    {
        $overrides = ['direction' => 'outgoing', 'items' => [['description' => 'Job', 'quantity' => '1', 'unit_price' => '1000', 'account_id' => 4, 'tax_rule_id' => 1, 'workspace_id' => 1]]];
        $a = $this->posted($overrides);
        $b = $this->draft($overrides);
        $this->invoices->transition(1, 1, $b->id, 'submit', []);
        $this->invoices->transition(1, 1, $b->id, 'approve', []);
        try {
            $this->invoices->transition(1, 1, $b->id, 'issue', []);
            self::fail();
        } catch (ValidationException $e) {
            self::assertSame('draft', $b->fresh()->issuance_status);
            self::assertSame(2, DB::table('accounting_settings')->where('company_id', 1)->value('next_invoice_number'));
        }
    }
}
