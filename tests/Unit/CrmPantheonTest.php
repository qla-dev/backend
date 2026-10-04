<?php

namespace Tests\Unit;

use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\PantheonConnector;
use App\Services\Crm\CrmPipeline;
use App\Services\Crm\PantheonCrmSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class CrmPantheonTest extends TestCase
{
    private $app;

    private PantheonCrmSync $sync;

    private CrmPipeline $crm;

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
        Schema::create('customers', fn (Blueprint $t) => [$t->id(), $t->string('tax_number')->nullable()]);
        Schema::create('documents', fn (Blueprint $t) => [$t->id()]);
        Schema::create('invoices', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id')->nullable(), $t->unsignedBigInteger('customer_user_id')->nullable(),
            $t->date('issued_at')->nullable(), $t->date('due_at')->nullable()]);
        Schema::create('invoice_items', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('invoice_id'), $t->decimal('quantity', 10, 2)->default(1), $t->decimal('unit_price', 14, 2)->default(0)]);
        foreach (['000001_create_accounting_module', '000002_add_smart_pos_and_pantheon_connector', '000003_add_pantheon_sync_state', '000004_create_crm_sales_pipeline'] as $migration) {
            (require __DIR__.'/../../database/migrations/2026_10_04_'.$migration.'.php')->up();
        }
        DB::table('users')->insert([['id' => 1, 'name' => 'Sales'], ['id' => 2, 'name' => 'Other']]);
        DB::table('companies')->insert([['id' => 1, 'owner_user_id' => 1, 'name' => 'Company A'], ['id' => 2, 'owner_user_id' => 2, 'name' => 'Company B']]);
        DB::table('customers')->insert(['id' => 7, 'tax_number' => '4200000000002']);
        DB::table('accounting_partners')->insert([['id' => 1, 'company_id' => 1, 'name' => 'Linked Buyer', 'tax_number' => '4200000000001', 'country_code' => 'BA'],
            ['id' => 2, 'company_id' => 2, 'name' => 'Foreign company partner', 'tax_number' => '999', 'country_code' => 'BA']]);
        DB::table('accounting_pantheon_connectors')->insert(['company_id' => 1, 'host' => 'synthetic.invalid', 'database' => 'TEST', 'schema' => 'main',
            'username' => 'test', 'password' => encrypt('unused'), 'updated_by' => 1]);
        DB::table('accounting_pantheon_links')->insert(['company_id' => 1, 'entity_type' => 'partner', 'local_id' => 1, 'pantheon_key' => 'BUYER DOO', 'direction' => 'sync', 'synced_by' => 1]);
        // Synthetic PANTHEON tables in the same in-memory database; no remote system is contacted.
        $this->table('tHE_Order', ['acKey', 'acDocType', 'adDate', 'acStatus', 'acReceiver', 'acContactPrsn', 'adDateValid', 'adDeliveryDeadline', 'acCurrency', 'anVAT', 'anForPay', 'acNote', 'acDoc1', 'acKeyView', 'acFinished', 'adTimeChg']);
        $this->table('tHE_OrderItem', ['acKey', 'anNo', 'acIdent', 'acName', 'anQty', 'acUM', 'anPrice', 'anRebate', 'anVAT', 'adDeliveryDeadline']);
        $this->table('tHE_LinkMoveItemOrderItem', ['acKey', 'anNo', 'acLnkKey', 'anLnkNo', 'anQty']);
        $this->table('tHE_Move', ['acKey', 'acDocType', 'adDate', 'acKeyView']);
        $this->table('tHE_SetSubj', ['acSubject', 'acName2', 'acCode']);
        $this->table('tHE_SetSubjContact', ['acSubject', 'anNo', 'acActive', 'acName', 'acSurname', 'acFunction']);
        $this->table('tHE_SetSubjContactAddress', ['acSubject', 'anNo', 'acType', 'acPhone']);
        $order = ['acStatus' => '1', 'acContactPrsn' => '', 'adDateValid' => null, 'adDeliveryDeadline' => null, 'acCurrency' => 'KM', 'anVAT' => '17', 'anForPay' => '117', 'acNote' => '', 'acDoc1' => '', 'acFinished' => '', 'adTimeChg' => '2026-10-01'];
        DB::table('tHE_Order')->insert([
            ['acKey' => '2601100000001', 'acDocType' => '0110', 'adDate' => '2026-09-01', 'acReceiver' => 'BUYER DOO', 'acKeyView' => '26-0110-000001'] + $order,
            ['acKey' => '2601100000002', 'acDocType' => '0110', 'adDate' => '2026-09-02', 'acReceiver' => 'BUYER DOO', 'acKeyView' => '26-0110-000002'] + $order,
            ['acKey' => '2601100000003', 'acDocType' => '0110', 'adDate' => '2026-09-03', 'acReceiver' => 'PLATFORM', 'acKeyView' => '26-0110-000003', 'acStatus' => '2'] + $order,
            ['acKey' => '2601100000004', 'acDocType' => '0110', 'adDate' => '2026-09-04', 'acReceiver' => 'UNKNOWN', 'acKeyView' => '26-0110-000004', 'acCurrency' => 'EUR'] + $order,
            ['acKey' => '2602000000001', 'acDocType' => '0200', 'adDate' => '2026-09-05', 'acReceiver' => 'SUPPLIER', 'acKeyView' => '26-0200-000001'] + $order,
        ]);
        foreach (['2601100000001', '2601100000002', '2601100000003', '2601100000004', '2602000000001'] as $key) {
            DB::table('tHE_OrderItem')->insert([['acKey' => $key, 'anNo' => 1, 'acIdent' => 'A1', 'acName' => 'Part A', 'anQty' => '10', 'acUM' => 'kom', 'anPrice' => '5', 'anRebate' => '0', 'anVAT' => '17', 'adDeliveryDeadline' => null],
                ['acKey' => $key, 'anNo' => 2, 'acIdent' => 'B1', 'acName' => 'Part B', 'anQty' => '10', 'acUM' => 'kom', 'anPrice' => '5', 'anRebate' => '0', 'anVAT' => '17', 'adDeliveryDeadline' => null]]);
        }
        // Offer 1 is still status "Ponuda" but fully delivered: PANTHEON status alone would report it as open.
        DB::table('tHE_LinkMoveItemOrderItem')->insert([['acKey' => '2635000000010', 'anNo' => 1, 'acLnkKey' => '2601100000001', 'anLnkNo' => 1, 'anQty' => '10'],
            ['acKey' => '2635000000010', 'anNo' => 2, 'acLnkKey' => '2601100000001', 'anLnkNo' => 2, 'anQty' => '10'],
            ['acKey' => '2635000000011', 'anNo' => 1, 'acLnkKey' => '2601100000002', 'anLnkNo' => 1, 'anQty' => '25']]);
        DB::table('tHE_Move')->insert([['acKey' => '2635000000010', 'acDocType' => '3500', 'adDate' => '2026-09-10', 'acKeyView' => '26-3500-000010'],
            ['acKey' => '2635000000011', 'acDocType' => '3500', 'adDate' => '2026-09-11', 'acKeyView' => '26-3500-000011']]);
        DB::table('tHE_SetSubj')->insert([['acSubject' => 'BUYER DOO', 'acName2' => 'Buyer d.o.o.', 'acCode' => '4200000000001'],
            ['acSubject' => 'PLATFORM', 'acName2' => 'Platform customer', 'acCode' => '4200000000002'], ['acSubject' => 'UNKNOWN', 'acName2' => 'Unknown GmbH', 'acCode' => '']]);
        DB::table('tHE_SetSubjContact')->insert(['acSubject' => 'BUYER DOO', 'anNo' => 1, 'acActive' => 'T', 'acName' => 'Ana', 'acSurname' => 'Anić', 'acFunction' => 'Nabavka']);
        DB::table('tHE_SetSubjContactAddress')->insert([['acSubject' => 'BUYER DOO', 'anNo' => 1, 'acType' => 'E', 'acPhone' => 'ana@example.test'],
            ['acSubject' => 'BUYER DOO', 'anNo' => 1, 'acType' => '0', 'acPhone' => '+387 33 000 000']]);
        $ledger = new AccountingLedger;
        $connector = new class($ledger) extends PantheonConnector
        {
            protected function remote(int $companyId): ConnectionInterface
            {
                return DB::connection();
            }
        };
        $this->sync = new PantheonCrmSync($connector, $ledger);
        $this->crm = new CrmPipeline($ledger);
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

    private function table(string $name, array $columns): void
    {
        Schema::create($name, function (Blueprint $t) use ($columns) {
            foreach ($columns as $c) {
                $t->string($c)->nullable();
            }
        });
    }

    private function doc(string $key): ?object
    {
        return DB::table('crm_documents')->where('pantheon_key', $key)->first();
    }

    public function test_pull_reads_only_sales_documents_and_derives_stage_from_delivery_links(): void
    {
        $summary = $this->sync->pull(1, 1);
        self::assertSame(4, $summary['documents']);
        self::assertNull($this->doc('2602000000001'), 'Supplier orders are not CRM documents.');
        self::assertSame('delivered', $this->doc('2601100000001')->stage);
        self::assertSame('in_delivery', $this->doc('2601100000002')->stage, 'Over-delivery on one line must not hide the undelivered line.');
        self::assertEquals(10, (float) $this->doc('2601100000002')->delivered_quantity);
        self::assertSame('order', $this->doc('2601100000003')->stage);
        self::assertSame('offer', $this->doc('2601100000004')->stage);
        self::assertSame('BAM', $this->doc('2601100000001')->currency);
        self::assertEquals(100, (float) $this->doc('2601100000001')->net_amount);
        self::assertSame(2, DB::table('crm_document_items')->where('crm_document_id', $this->doc('2601100000001')->id)->count());
    }

    public function test_customers_are_matched_to_existing_clients_without_duplicates(): void
    {
        $this->sync->pull(1, 1);
        self::assertSame(1, (int) $this->doc('2601100000001')->partner_id);
        self::assertSame('Linked Buyer', $this->doc('2601100000001')->customer_name);
        self::assertSame(7, (int) $this->doc('2601100000003')->customer_id, 'Platform customer is matched by tax ID.');
        self::assertNull($this->doc('2601100000004')->partner_id);
        self::assertSame(2, DB::table('accounting_partners')->count(), 'CRM pull never creates partners.');
        $contact = DB::table('crm_contacts')->first();
        self::assertSame(['Ana Anić', 'ana@example.test', '+387 33 000 000', 1], [$contact->name, $contact->email, $contact->phone, (int) $contact->partner_id]);
    }

    public function test_repeated_pull_is_idempotent_and_keeps_smartfreight_fields(): void
    {
        $this->sync->pull(1, 1);
        $offer = $this->doc('2601100000004');
        $this->crm->update(1, 1, $offer->id, ['stage' => 'lost', 'lost_reason' => 'Price too high', 'owner_user_id' => 2]);
        $this->crm->followUp(1, 1, ['crm_document_id' => $offer->id, 'due_on' => '2026-10-10', 'note' => 'Call back']);
        $this->sync->pull(1, 1, true);
        self::assertSame(4, DB::table('crm_documents')->count());
        self::assertSame(8, DB::table('crm_document_items')->count());
        $offer = $this->doc('2601100000004');
        self::assertSame(['lost', 'Price too high', 2, '2026-10-10'], [$offer->stage, $offer->lost_reason, (int) $offer->owner_user_id, $offer->next_follow_up_on]);
        // When PANTHEON progresses a lost offer, PANTHEON wins.
        DB::table('tHE_Order')->where('acKey', '2601100000004')->update(['acStatus' => '2']);
        $this->sync->pull(1, 1, true);
        self::assertSame('order', $this->doc('2601100000004')->stage);
    }

    public function test_pantheon_progress_cannot_be_overridden_and_lost_needs_reason(): void
    {
        $this->sync->pull(1, 1);
        try {
            $this->crm->update(1, 1, $this->doc('2601100000001')->id, ['stage' => 'offer']);
            self::fail('Delivered PANTHEON document was moved back.');
        } catch (ValidationException) {
        }
        $this->expectException(ValidationException::class);
        $this->crm->update(1, 1, $this->doc('2601100000004')->id, ['stage' => 'lost']);
    }

    public function test_smartfreight_lead_and_report_by_customer_and_currency(): void
    {
        $this->sync->pull(1, 1);
        $lead = $this->crm->create(1, 1, ['stage' => 'lead', 'partner_id' => 1, 'title' => 'Annual frame contract', 'currency' => 'BAM', 'total_amount' => '500', 'issued_on' => '2026-09-20']);
        self::assertSame('smartfreight', $lead->source);
        self::assertNull($lead->pantheon_key);
        $report = $this->crm->report(1, '2026-09-01', '2026-09-30');
        self::assertSame(5, $report['documents']);
        self::assertSame(3, $report['converted']);
        self::assertSame(2, $report['open']);
        $buyer = collect($report['customers'])->firstWhere('partner_id', 1);
        self::assertSame([3, 2, 1, '734.00', '234.00', '500.00'], [$buyer['documents'], $buyer['converted'], $buyer['open'], $buyer['total_value'], $buyer['converted_value'], $buyer['open_value']]);
        self::assertSame(['BAM', 'EUR'], collect($report['customers'])->pluck('currency')->unique()->sort()->values()->all(), 'Currencies are never summed together.');
    }

    public function test_cross_company_partner_is_refused(): void
    {
        $this->expectException(ValidationException::class);
        $this->crm->create(1, 1, ['stage' => 'lead', 'partner_id' => 2, 'title' => 'Not ours', 'currency' => 'BAM']);
    }
}
