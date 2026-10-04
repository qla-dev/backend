<?php

namespace Tests\Unit;

use App\Models\Invoice;
use App\Services\Accounting\AccountingInvoices;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\PantheonConnector;
use App\Services\Fiscal\FiscalBridge;
use App\Services\Fiscal\SmartPos;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SmartPosPantheonTest extends TestCase
{
    private $app;

    private AccountingLedger $ledger;

    private AccountingInvoices $invoices;

    /** @var array<int, array{0: string, 1: array}> */
    public array $sent = [];

    public mixed $answer = ['ok' => true, 'fiscalNumber' => 1084];

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
        self::assertSame(':memory:', $connection->getConfig('database'));
        self::assertSame('', $connection->select('PRAGMA database_list')[0]->file);
        Schema::create('users', fn (Blueprint $t) => [$t->id(), $t->string('name')]);
        Schema::create('companies', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('owner_user_id'), $t->string('name')]);
        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('uploaded_by_user_id');
            $t->string('name');
            $t->string('mime_type')->nullable();
            $t->string('type');
            $t->string('reference')->nullable();
            $t->string('path');
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->timestamps();
        });
        Schema::create('shipment_workspace', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('provider_company_id'), $t->unsignedBigInteger('load_id')]);
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
        (require __DIR__.'/../../database/migrations/2026_10_04_000002_add_smart_pos_and_pantheon_connector.php')->up();
        (require __DIR__.'/../../database/migrations/2026_10_04_000003_add_pantheon_sync_state.php')->up();
        DB::table('users')->insert(['id' => 1, 'name' => 'Cashier']);
        DB::table('companies')->insert(['id' => 1, 'owner_user_id' => 1, 'name' => 'Company A']);
        DB::table('accounting_settings')->insert(['company_id' => 1, 'jurisdiction' => 'FBiH', 'base_currency' => 'BAM']);
        DB::table('accounting_partners')->insert(['id' => 1, 'company_id' => 1, 'name' => 'Buyer', 'tax_number' => '4200000000001', 'country_code' => 'BA']);
        DB::table('accounting_periods')->insert(['id' => 1, 'company_id' => 1, 'name' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        foreach ([['2110', 'asset'], ['6120', 'income'], ['4700', 'liability']] as $n => [$code, $kind]) {
            DB::table('accounting_accounts')->insert(['id' => $n + 1, 'company_id' => 1, 'code' => $code, 'name' => $kind, 'kind' => $kind, 'approved_by' => 1]);
        }
        DB::table('accounting_tax_rules')->insert(['id' => 1, 'company_id' => 1, 'name' => 'Synthetic 17% fixture — no legal assertion', 'jurisdiction' => 'FBiH', 'tax_type' => 'vat',
            'treatment' => 'test_standard', 'conditions' => 'Synthetic fixture', 'rate' => '17', 'deductible_percent' => '100', 'effective_from' => '2026-01-01', 'applies_from' => '2026-01-01',
            'source_url' => 'https://example.test/rule', 'article' => 'test', 'version' => 'test-v1', 'verification_status' => 'approved', 'approved_by' => 1]);
        $this->ledger = new AccountingLedger;
        $this->invoices = new AccountingInvoices($this->ledger);
        $this->app['config']->set('fiscal.tax_labels', ['17.0000' => 'E']);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            // No container flush: later legacy tests rely on facade instances resolved earlier in the process.
            DB::disconnect();
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    private function pos(): SmartPos
    {
        $test = $this;
        $bridge = new class($test) extends FiscalBridge
        {
            public function __construct(private SmartPosPantheonTest $test) {}

            public function configured(): bool
            {
                return true;
            }

            public function run(array $args, ?int $timeout = null): array
            {
                return $this->runWithRequest($args[0], ['args' => $args]);
            }

            public function runWithRequest(string $command, array $request, ?int $timeout = null): array
            {
                $this->test->sent[] = [$command, $request];
                if ($this->test->answer instanceof \Throwable) {
                    throw $this->test->answer;
                }

                return $this->test->answer;
            }
        };

        return new SmartPos($bridge, $this->ledger);
    }

    private function issued(string $currency = 'BAM'): Invoice
    {
        $i = $this->invoices->save(1, 1, ['direction' => 'outgoing', 'partner_id' => 1, 'partner_name' => 'Buyer', 'currency' => $currency,
            'issued_at' => '2026-10-04', 'due_at' => '2026-10-20', 'event_date' => '2026-10-04', 'tax_date' => '2026-10-04', 'posting_date' => '2026-10-04',
            'items' => [['description' => 'Transport Sarajevo - Mostar', 'quantity' => '2', 'unit_price' => '50', 'tax_rule_id' => 1, 'account_id' => 2]]]);
        foreach (['submit', 'approve', 'issue'] as $action) {
            $i = $this->invoices->transition(1, 1, $i->id, $action, []);
        }

        return $i;
    }

    public function test_issued_invoice_is_fiscalised_in_place_without_a_second_invoice(): void
    {
        $i = $this->issued();
        $fiscal = $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-1');
        self::assertSame('fiscalised', $fiscal->fiscal_status);
        self::assertSame(1084, (int) $fiscal->fiscal_number);
        self::assertSame(1, Invoice::count());
        [$command, $request] = $this->sent[0];
        self::assertSame('fiscal:fiskalni-racun', $command);
        self::assertSame('117.00', $request['total']);
        self::assertSame('E', $request['lines'][0]['tax_label']);
        self::assertSame('58.50', $request['lines'][0]['price']);
        self::assertSame('4200000000001', $request['buyer']['tax_number']);
    }

    public function test_repeated_request_and_second_fiscalisation_never_print_twice(): void
    {
        $i = $this->issued();
        $this->pos()->fiscalise(1, 1, $i->id, 'card', 'req-1');
        $this->pos()->fiscalise(1, 1, $i->id, 'card', 'req-1');
        self::assertCount(1, $this->sent);
        $this->expectException(ValidationException::class);
        $this->pos()->fiscalise(1, 1, $i->id, 'card', 'req-2');
    }

    public function test_draft_or_foreign_currency_invoice_is_refused(): void
    {
        $draft = $this->invoices->save(1, 1, ['direction' => 'outgoing']);
        try {
            $this->pos()->fiscalise(1, 1, $draft->id, 'cash', 'req-draft');
            self::fail('Draft was fiscalised.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $this->pos()->fiscalise(1, 1, $this->issued('EUR')->id, 'cash', 'req-eur');
    }

    public function test_unknown_vat_rate_is_not_sent_as_tax_free(): void
    {
        $this->app['config']->set('fiscal.tax_labels', ['0.0000' => 'K']);
        $i = $this->issued();
        try {
            $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-1');
            self::fail('Unknown label was accepted.');
        } catch (ValidationException) {
        }
        self::assertSame('none', $i->fresh()->fiscal_status);
        self::assertSame([], $this->sent);
    }

    public function test_device_error_allows_retry_but_timeout_requires_manual_confirmation(): void
    {
        $i = $this->issued();
        $this->answer = ValidationException::withMessages(['fiscal' => 'Paper out']);
        self::assertSame('failed', $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-1')->fiscal_status);
        $this->answer = new ProcessTimedOutException(new Process(['php']), ProcessTimedOutException::TYPE_GENERAL);
        self::assertSame('unconfirmed', $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-2')->fiscal_status);
        try {
            $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-3');
            self::fail('Unconfirmed receipt was retried.');
        } catch (ValidationException) {
        }
        $confirmed = $this->pos()->confirm(1, 1, $i->id, 555);
        self::assertSame('fiscalised', $confirmed->fiscal_status);
        self::assertSame(555, (int) $confirmed->fiscal_number);
        self::assertCount(2, $this->sent);
    }

    public function test_refund_references_original_fiscal_number(): void
    {
        $i = $this->issued();
        $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-1');
        $this->answer = ['ok' => true, 'fiscalNumber' => 77];
        $refunded = $this->pos()->refund(1, 1, $i->id, 'Customer complaint', 'req-r');
        self::assertSame('refunded', $refunded->fiscal_status);
        self::assertSame(77, (int) $refunded->fiscal_refund_number);
        self::assertSame(1084, $this->sent[1][1]['original_fiscal_number']);
        self::assertSame('fiscal:reklamirani-racun', $this->sent[1][0]);
    }

    public function test_duplicate_report_uses_stored_fiscal_number(): void
    {
        $i = $this->issued();
        $this->pos()->fiscalise(1, 1, $i->id, 'cash', 'req-1');
        $this->pos()->report(1, 1, 'duplicate', ['invoice_id' => $i->id, 'request_key' => 'dup-1']);
        self::assertContains('--fiscal-number=1084', $this->sent[1][1]['args']);
        $summary = $this->pos()->summary(1, '2026-01-01', '2030-12-31');
        self::assertSame('117.00', $summary['by_payment'][0]['total']);
    }

    private function pantheon(): PantheonConnector
    {
        DB::table('accounting_pantheon_connectors')->insert(['company_id' => 1, 'host' => 'synthetic.invalid', 'database' => 'TEST', 'schema' => 'main',
            'username' => 'test', 'password' => encrypt('unused'), 'allow_write' => true, 'clerk_id' => 45, 'updated_by' => 1]);
        // Synthetic Pantheon tables in the same in-memory SQLite database; no remote system is contacted.
        Schema::create('tHE_SetAccount', fn (Blueprint $t) => [$t->string('acAcct'), $t->string('acName'), $t->string('acPermitPost'), $t->string('acSubject')]);
        Schema::create('tHE_SetSubj', fn (Blueprint $t) => [$t->string('acSubject'), $t->string('acName2'), $t->string('acCode'), $t->string('acRegNo')->default(''),
            $t->string('acAddress')->default(''), $t->string('acPost')->default(''), $t->string('acCountry')->default(''), $t->string('acVATCodePrefix')->default(''), $t->string('acActive')->default('T'),
            $t->string('acWarehouse')->default('F'), $t->string('acDept')->default('F')]);
        Schema::create('tHE_AcctTrans', function (Blueprint $t) {
            foreach (['acKey', 'acDocType', 'adDate', 'adDateOfEntry', 'anClerk', 'anDebit', 'anCredit', 'acNote', 'acKeyView', 'anUserIns', 'anUserChg'] as $c) {
                $t->string($c)->nullable();
            }
        });
        Schema::create('tHE_AcctTransItem', function (Blueprint $t) {
            foreach (['acKey', 'anNo', 'acAcct', 'acSubject', 'anDebit', 'anCredit', 'acCurrency', 'anFXRate', 'anValDebit', 'anValCredit', 'acDoc', 'adDateDoc', 'adDateDue', 'adDateVAT', 'acNote', 'anUserIns', 'anUserChg'] as $c) {
                $t->string($c)->nullable();
            }
        });
        DB::table('tHE_SetAccount')->insert([['acAcct' => '2110', 'acName' => 'Kupci', 'acPermitPost' => 'D', 'acSubject' => 'T'],
            ['acAcct' => '6120', 'acName' => 'Prihodi', 'acPermitPost' => 'D', 'acSubject' => 'F'], ['acAcct' => '4700', 'acName' => 'PDV', 'acPermitPost' => 'D', 'acSubject' => 'F'],
            ['acAcct' => '7000', 'acName' => 'Zatvaranje', 'acPermitPost' => 'D', 'acSubject' => 'F'], ['acAcct' => '61', 'acName' => 'Sintetika', 'acPermitPost' => 'N', 'acSubject' => 'F']]);
        DB::table('tHE_SetSubj')->insert([['acSubject' => 'BUYER DOO', 'acName2' => 'Buyer d.o.o.', 'acCode' => '4200000000001', 'acVATCodePrefix' => 'BA'],
            ['acSubject' => 'NO COUNTRY', 'acName2' => 'Unknown', 'acCode' => '999', 'acVATCodePrefix' => '']]);
        DB::table('tHE_SetSubj')->insert(['acSubject' => 'Skladište sirovina', 'acName2' => '', 'acCode' => '', 'acWarehouse' => 'T']);
        DB::table('tHE_AcctTrans')->insert(['acKey' => '2642000000016', 'acDocType' => '4200', 'acNote' => '']);

        return new class($this->ledger) extends PantheonConnector
        {
            protected function remote(int $companyId): ConnectionInterface
            {
                return DB::connection();
            }
        };
    }

    public function test_pantheon_sync_adopts_existing_records_and_keeps_pending_ones(): void
    {
        $connector = $this->pantheon();
        $preview = collect($connector->preview(1, 'accounts'))->keyBy('code');
        self::assertTrue($preview['2110']['exists']);
        self::assertNull($preview['7000']['kind']);
        $first = $connector->sync(1, 1);
        // 2110/6120/4700 already exist locally: linked and renamed, never duplicated or re-kinded.
        self::assertSame(['created' => 1, 'updated' => 3, 'linked' => 3, 'deactivated' => 0], collect($first['accounts'])->except('pending')->all());
        self::assertSame(['kind_required'], array_column($first['accounts']['pending'], 'reason'));
        self::assertSame('Kupci', DB::table('accounting_accounts')->where('code', '2110')->value('name'));
        self::assertSame('asset', DB::table('accounting_accounts')->where('code', '2110')->value('kind'));
        self::assertSame(0, (int) DB::table('accounting_accounts')->where('code', '61')->value('active'));
        self::assertSame(['created' => 0, 'updated' => 1, 'linked' => 1], collect($first['partners'])->except('pending')->all());
        self::assertSame('Buyer d.o.o.', DB::table('accounting_partners')->where('id', 1)->value('name'));
        self::assertSame(['NO COUNTRY'], array_column($first['partners']['pending'], 'key'));
        self::assertSame(0, DB::table('accounting_partners')->where('name', 'Skladište sirovina')->count());
        self::assertSame('ok', DB::table('accounting_pantheon_connectors')->value('last_sync_status'));
        self::assertFalse($first['entries']['enabled']);
        $again = $connector->sync(1, 1);
        self::assertSame(0, $again['accounts']['created'] + $again['accounts']['updated'] + $again['accounts']['linked']);
        self::assertSame(0, $again['partners']['updated'] + $again['partners']['linked']);
    }

    public function test_pantheon_sync_follows_remote_changes_and_resolves_pending_choices(): void
    {
        $connector = $this->pantheon();
        $connector->sync(1, 1);
        DB::table('tHE_SetAccount')->where('acAcct', '6120')->update(['acName' => 'Prihodi od prevoza']);
        DB::table('tHE_SetAccount')->where('acAcct', '4700')->delete();
        DB::table('tHE_SetSubj')->where('acSubject', 'BUYER DOO')->update(['acAddress' => 'Zmaja od Bosne 1']);
        DB::table('accounting_pantheon_connectors')->update(['account_kinds' => json_encode(['7000' => 'equity']), 'default_country_code' => 'BA']);
        $result = $connector->sync(1, 1);
        self::assertSame('Prihodi od prevoza', DB::table('accounting_accounts')->where('code', '6120')->value('name'));
        self::assertSame(0, (int) DB::table('accounting_accounts')->where('code', '4700')->value('active'));
        self::assertSame(1, $result['accounts']['deactivated']);
        self::assertSame('equity', DB::table('accounting_accounts')->where('code', '7000')->value('kind'));
        self::assertSame('Zmaja od Bosne 1', DB::table('accounting_partners')->where('id', 1)->value('address'));
        self::assertSame('BA', DB::table('accounting_partners')->where('tax_number', '999')->value('country_code'));
        self::assertSame([], $result['accounts']['pending']);
        self::assertSame([], $result['partners']['pending']);
    }

    public function test_pantheon_sync_pushes_posted_entries_once_when_enabled(): void
    {
        $connector = $this->pantheon();
        $i = $this->issued();
        $this->invoices->transition(1, 1, $i->id, 'post', ['control_account_id' => 1, 'vat_account_id' => 3]);
        DB::table('accounting_pantheon_connectors')->update(['push_entries_from' => '2026-10-01', 'sync_enabled' => true]);
        self::assertCount(1, $connector->due());
        $result = $connector->sync(1, 1);
        self::assertSame(1, $result['entries']['exported']);
        self::assertSame(2, DB::table('tHE_AcctTrans')->count());
        self::assertSame([], $connector->due());
        self::assertSame(0, $connector->sync(1, 1)['entries']['exported']);
        self::assertSame(2, DB::table('tHE_AcctTrans')->count());
    }

    public function test_pantheon_export_dry_run_then_single_write_with_pantheon_key_format(): void
    {
        $connector = $this->pantheon();
        $i = $this->issued();
        $this->invoices->transition(1, 1, $i->id, 'post', ['control_account_id' => 1, 'vat_account_id' => 3]);
        $dry = $connector->export(1, 1, '2026-10-01', '2026-10-31', false);
        self::assertTrue($dry['dry_run']);
        self::assertSame([], $dry['documents'][0]['problems']);
        self::assertSame(1, DB::table('tHE_AcctTrans')->count());
        $written = $connector->export(1, 1, '2026-10-01', '2026-10-31', true);
        self::assertSame('2642000000017', $written['exported'][0]['pantheon_key']);
        $lines = DB::table('tHE_AcctTransItem')->where('acKey', '2642000000017')->get();
        self::assertSame('BUYER DOO', $lines->firstWhere('acAcct', '2110')->acSubject);
        self::assertSame('KM', $lines->first()->acCurrency);
        self::assertSame('26-4200-000017', DB::table('tHE_AcctTrans')->where('acKey', '2642000000017')->value('acKeyView'));
        $again = $connector->export(1, 1, '2026-10-01', '2026-10-31', true);
        self::assertSame([], $again['exported']);
        self::assertSame(2, DB::table('tHE_AcctTrans')->count());
    }

    public function test_pantheon_write_is_refused_unless_enabled(): void
    {
        $connector = $this->pantheon();
        DB::table('accounting_pantheon_connectors')->update(['allow_write' => false]);
        $this->expectException(ValidationException::class);
        $connector->export(1, 1, '2026-10-01', '2026-10-31', true);
    }
}
