<?php

namespace Tests\Unit;

use App\Models\ShipmentWorkspace;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\PantheonConnector;
use App\Services\Ops\OpsOrders;
use App\Services\Ops\OpsPantheonSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class OpsWorkOrderTest extends TestCase
{
    private $app;

    private OpsOrders $ops;

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
        Schema::create('customers', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('user_id')->nullable(), $t->string('company_name')->nullable(), $t->string('tax_number')->nullable()]);
        Schema::create('documents', fn (Blueprint $t) => [$t->id(), $t->string('name')->nullable(), $t->unsignedBigInteger('uploaded_by_user_id')->nullable()]);
        Schema::create('loads', fn (Blueprint $t) => [$t->id(), $t->string('title')->nullable(), $t->string('transport_type')->nullable(), $t->boolean('for_storage')->default(false)]);
        Schema::create('load_stops', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('load_id'), $t->timestamp('window_starts_at')->nullable(), $t->timestamp('window_ends_at')->nullable()]);
        Schema::create('load_drafts', fn (Blueprint $t) => [$t->id()]);
        Schema::create('shipment_workspace', function (Blueprint $t) {
            $t->id();
            $t->string('reference');
            $t->unsignedBigInteger('load_id');
            $t->unsignedBigInteger('accepted_offer_id')->nullable();
            $t->unsignedBigInteger('customer_user_id');
            $t->unsignedBigInteger('provider_company_id')->nullable();
            $t->unsignedBigInteger('provider_user_id')->nullable();
            $t->string('status')->default('booked');
            $t->char('currency', 3);
            $t->decimal('agreed_amount', 14, 2);
            foreach (['load_snapshot', 'offer_snapshot', 'parties_snapshot', 'operational_checklist', 'additional_charges'] as $c) {
                $t->json($c)->nullable();
            }
            $t->timestamp('booked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('invoices', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('company_id')->nullable(), $t->unsignedBigInteger('customer_user_id')->nullable(),
            $t->date('issued_at')->nullable(), $t->date('due_at')->nullable()]);
        Schema::create('invoice_items', fn (Blueprint $t) => [$t->id(), $t->unsignedBigInteger('invoice_id'), $t->decimal('quantity', 10, 2)->default(1), $t->decimal('unit_price', 14, 2)->default(0)]);
        foreach (['000001_create_accounting_module', '000002_add_smart_pos_and_pantheon_connector', '000003_add_pantheon_sync_state', '000004_create_crm_sales_pipeline', '000005_create_ops_work_orders'] as $m) {
            (require __DIR__.'/../../database/migrations/2026_10_04_'.$m.'.php')->up();
        }
        DB::table('users')->insert([['id' => 1, 'name' => 'Dispatcher'], ['id' => 2, 'name' => 'Customer']]);
        DB::table('companies')->insert(['id' => 1, 'owner_user_id' => 1, 'name' => 'Forwarder']);
        DB::table('customers')->insert(['id' => 5, 'user_id' => 2, 'company_name' => 'Customer d.o.o.']);
        DB::table('accounting_partners')->insert(['id' => 1, 'company_id' => 1, 'name' => 'Customer d.o.o.', 'country_code' => 'BA', 'customer_id' => 5]);
        DB::table('accounting_pantheon_links')->insert(['company_id' => 1, 'entity_type' => 'partner', 'local_id' => 1, 'pantheon_key' => 'CUSTOMER DOO', 'direction' => 'sync', 'synced_by' => 1]);
        $template = DB::table('ops_service_templates')->insertGetId(['company_id' => 1, 'code' => 'FTL-UVOZ', 'name' => 'FTL uvoz', 'transport_type' => 'road', 'created_by' => 1]);
        DB::table('ops_service_template_items')->insert([
            ['template_id' => $template, 'position' => 1, 'item_type' => 'cost', 'item_code' => 'VOZARINA', 'description' => 'Vozarina podizvođača', 'unit' => 'KOM', 'planned_qty' => 1, 'planned_price' => 800],
            ['template_id' => $template, 'position' => 2, 'item_type' => 'operation', 'item_code' => 'DISPECING', 'description' => 'Dispečing', 'unit' => 'H', 'planned_qty' => 2, 'planned_price' => 25],
            ['template_id' => $template, 'position' => 3, 'item_type' => 'revenue', 'item_code' => 'PRIHOD', 'description' => 'Template revenue (replaced)', 'unit' => 'KOM', 'planned_qty' => 1, 'planned_price' => 1]]);
        DB::table('crm_documents')->insert(['id' => 9, 'company_id' => 1, 'source' => 'smartfreight', 'stage' => 'offer', 'customer_name' => 'Customer d.o.o.', 'currency' => 'BAM']);
        DB::table('loads')->insert(['id' => 3, 'title' => 'Sarajevo - Hamburg', 'transport_type' => 'road', 'crm_document_id' => 9]);
        DB::table('load_stops')->insert([['load_id' => 3, 'window_starts_at' => '2026-10-05 08:00:00'], ['load_id' => 3, 'window_starts_at' => '2026-10-07 14:00:00']]);
        $this->ops = $this->app->make(OpsOrders::class);
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

    private function book(?int $company = 1): ShipmentWorkspace
    {
        return ShipmentWorkspace::query()->create(['reference' => 'AB12CD', 'load_id' => 3, 'customer_user_id' => 2, 'provider_company_id' => $company, 'provider_user_id' => 1,
            'status' => 'booked', 'currency' => 'BAM', 'agreed_amount' => '1200.00', 'load_snapshot' => ['title' => 'Sarajevo - Hamburg'], 'offer_snapshot' => [],
            'parties_snapshot' => [], 'operational_checklist' => [], 'booked_at' => now()]);
    }

    private function order(): object
    {
        return DB::table('ops_orders')->first();
    }

    public function test_booked_shipment_becomes_one_work_order_with_template_lines(): void
    {
        $workspace = $this->book();
        $order = $this->order();
        self::assertSame(['open', 1, 3, 9, 1, '26-SF-000001'], [$order->status, (int) $order->workspace_id, (int) $order->load_id, (int) $order->crm_document_id, (int) $order->customer_partner_id, $order->reference]);
        self::assertSame('2026-10-05 08:00:00', (string) $order->planned_start_at);
        $items = DB::table('ops_order_items')->where('order_id', $order->id)->orderBy('position')->get();
        self::assertSame(['cost', 'operation', 'revenue'], $items->pluck('item_type')->all());
        self::assertEquals(1200, (float) $items[2]->planned_price, 'Revenue is the agreed shipment amount, not the template placeholder.');
        self::assertSame('order', DB::table('crm_documents')->where('id', 9)->value('stage'));
        self::assertNotNull($this->ops->createFromWorkspace($workspace));
        self::assertSame(1, DB::table('ops_orders')->count(), 'A retry returns the existing order.');
    }

    public function test_independent_driver_shipment_has_no_company_work_order(): void
    {
        $this->book(null);
        self::assertSame(0, DB::table('ops_orders')->count());
    }

    public function test_work_order_and_crm_follow_the_shipment(): void
    {
        $workspace = $this->book();
        $workspace->update(['status' => 'in_execution']);
        self::assertSame('in_progress', $this->order()->status);
        self::assertSame('in_delivery', DB::table('crm_documents')->where('id', 9)->value('stage'));
        $workspace->update(['status' => 'completed']);
        self::assertTrue(DB::table('ops_events')->where('event_type', 'delivered')->exists());
        self::assertSame('delivered', DB::table('crm_documents')->where('id', 9)->value('stage'));
    }

    public function test_close_is_partial_without_pod_or_actual_cost_then_final_and_idempotent(): void
    {
        $workspace = $this->book();
        $order = $this->order();
        try {
            $this->ops->close(1, 1, $order->id);
            self::fail('Closed before delivery.');
        } catch (ValidationException) {
        }
        $workspace->update(['status' => 'completed']);
        $operation = DB::table('ops_order_items')->where('order_id', $order->id)->where('item_type', 'operation')->first();
        $this->ops->logWork(1, 1, $order->id, ['order_item_id' => $operation->id, 'work_date' => '2026-10-06', 'minutes' => '90']);
        self::assertEquals(1.5, (float) DB::table('ops_order_items')->where('id', $operation->id)->value('actual_qty'));
        $partial = $this->ops->close(1, 1, $order->id);
        self::assertSame('partially_closed', $partial->status);
        self::assertStringContainsString('missing_pod', $partial->close_problems);
        $cost = DB::table('ops_order_items')->where('order_id', $order->id)->where('item_type', 'cost')->first();
        $this->ops->saveItem(1, 1, $order->id, ['id' => $cost->id, 'actual_price' => '850', 'actual_qty' => '1']);
        $this->ops->event(1, 1, $order->id, ['event_type' => 'pod']);
        self::assertSame('closed', $this->ops->close(1, 1, $order->id)->status);
        $revision = $this->order()->revision;
        self::assertSame('closed', $this->ops->close(1, 1, $order->id)->status);
        self::assertSame($revision, $this->order()->revision);
        $this->expectException(ValidationException::class);
        $this->ops->saveItem(1, 1, $order->id, ['id' => $cost->id, 'actual_price' => '1']);
    }

    public function test_margin_replaces_estimates_with_actuals(): void
    {
        $this->book();
        $order = $this->order();
        $cost = DB::table('ops_order_items')->where('order_id', $order->id)->where('item_type', 'cost')->first();
        self::assertSame('350.00', $this->ops->margin($order->id)['plan']['margin']);
        $this->ops->saveItem(1, 1, $order->id, ['id' => $cost->id, 'actual_price' => '900', 'actual_qty' => '1']);
        self::assertSame('250.00', $this->ops->margin($order->id)['actual']['margin']);
    }

    private function sync(): OpsPantheonSync
    {
        DB::table('accounting_pantheon_connectors')->insert(['company_id' => 1, 'host' => 'synthetic.invalid', 'database' => 'TEST', 'schema' => 'main', 'username' => 'test',
            'password' => encrypt('unused'), 'allow_write' => true, 'clerk_id' => 45, 'updated_by' => 1]);
        DB::table('ops_pantheon_sync')->insert(['company_id' => 1, 'sync_enabled' => true, 'order_doc_type' => '6A00', 'push_orders_from' => '2026-01-01', 'updated_by' => 1]);
        // Synthetic PANTHEON tables in the same in-memory database; no remote system is contacted.
        foreach (['tHF_WOEx' => ['acKey', 'acDocType', 'acDocTypeView', 'adDate', 'acIdent', 'acName', 'acUM', 'anPlanQty', 'anProducedQty', 'acStatusMF', 'acStatus', 'anPriority', 'adSchedStartTime',
            'adSchedEndTime', 'acReceiver', 'acConsignee', 'acDept', 'acNote', 'acKeyView', 'acCreateFrom', 'anUserIns', 'anUserChg', 'adTimeChg'],
            'tHF_WOExItem' => ['acKey', 'anNo', 'anVariant', 'acIdent', 'acDescr', 'acOperationType', 'acUM', 'anPlanQty', 'anQty', 'anPrice', 'acIssueFinished', 'acDelayType', 'anIssuePerc', 'anUserIns', 'anUserChg'],
            'tHE_SetItem' => ['acIdent']] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                foreach ($columns as $c) {
                    $t->string($c)->nullable();
                }
            });
        }
        DB::table('tHE_SetItem')->insert([['acIdent' => 'FTL-UVOZ'], ['acIdent' => 'VOZARINA']]);
        DB::table('tHF_WOEx')->insert(['acKey' => '266A000000004', 'acDocType' => '6A00', 'acNote' => 'other']);

        return new OpsPantheonSync(new class(new AccountingLedger) extends PantheonConnector
        {
            protected function remote(int $companyId): ConnectionInterface
            {
                return DB::connection();
            }
        }, new AccountingLedger);
    }

    public function test_sync_waits_for_unknown_item_codes_then_pushes_once_without_revenue(): void
    {
        $this->book();
        $sync = $this->sync();
        $first = $sync->sync(1);
        self::assertStringContainsString('DISPECING', $first['waiting'][0]['problems'][0]);
        self::assertSame(1, DB::table('tHF_WOEx')->count(), 'Nothing is written while an item code is unknown in PANTHEON.');
        DB::table('tHE_SetItem')->insert(['acIdent' => 'DISPECING']);
        $pushed = $sync->sync(1);
        self::assertSame('266A000000005', $pushed['pushed'][0]['pantheon_key']);
        $header = DB::table('tHF_WOEx')->where('acKey', '266A000000005')->first();
        self::assertSame(['CUSTOMER DOO', 'O', '26-6A00-000005'], [$header->acReceiver, $header->acStatusMF, $header->acKeyView]);
        self::assertStringStartsWith('SF:1:ops:', $header->acNote);
        self::assertSame(['M', 'D'], DB::table('tHF_WOExItem')->orderBy('anNo')->pluck('acOperationType')->all(), 'Revenue is invoiced through Accounting, never pushed as a work order line.');
        self::assertSame([], $sync->sync(1)['pushed'], 'An unchanged order is not pushed again.');
        $this->ops->event(1, 1, $this->order()->id, ['event_type' => 'loaded']);
        self::assertCount(1, $sync->sync(1)['updated']);
        self::assertSame(1, DB::table('tHF_WOEx')->where('acNote', 'like', 'SF:%')->count());
    }

    public function test_dry_run_and_disabled_write_never_touch_pantheon(): void
    {
        $this->book();
        $sync = $this->sync();
        DB::table('tHE_SetItem')->insert(['acIdent' => 'DISPECING']);
        self::assertSame(['write_disabled'], $sync->sync(1, false)['waiting'][0]['problems']);
        DB::table('accounting_pantheon_connectors')->update(['allow_write' => false]);
        $sync->sync(1);
        self::assertSame(1, DB::table('tHF_WOEx')->count());
    }

    public function test_pantheon_closing_is_pulled_and_a_closed_remote_order_is_never_changed(): void
    {
        $this->book();
        $sync = $this->sync();
        DB::table('tHE_SetItem')->insert(['acIdent' => 'DISPECING']);
        $sync->sync(1);
        DB::table('tHF_WOEx')->where('acKey', '266A000000005')->update(['acStatusMF' => 'Z', 'anProducedQty' => '1', 'acName' => 'Closed in PANTHEON']);
        $result = $sync->sync(1);
        self::assertSame('closed', $this->order()->status);
        self::assertCount(1, $result['pulled_closed']);
        self::assertSame('Closed in PANTHEON', DB::table('tHF_WOEx')->where('acKey', '266A000000005')->value('acName'));
    }
}
