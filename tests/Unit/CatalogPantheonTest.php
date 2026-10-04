<?php

namespace Tests\Unit;

use App\Models\ShipmentWorkspace;
use App\Services\Accounting\AccountingLedger;
use App\Services\Accounting\PantheonConnector;
use App\Services\Catalog\CatalogProducts;
use App\Services\Catalog\PantheonCatalogSync;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

class CatalogPantheonTest extends TestCase
{
    private $app;

    private CatalogProducts $catalog;

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
        Schema::create('documents', fn (Blueprint $t) => [$t->id()]);
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
        foreach (['000001_create_accounting_module', '000002_add_smart_pos_and_pantheon_connector', '000003_add_pantheon_sync_state', '000004_create_crm_sales_pipeline',
            '000005_create_ops_work_orders', '000006_add_crm_and_ops_pantheon_push', '000007_create_catalog_products'] as $m) {
            (require __DIR__.'/../../database/migrations/2026_10_04_'.$m.'.php')->up();
        }
        DB::table('users')->insert(['id' => 1, 'name' => 'Owner']);
        DB::table('companies')->insert(['id' => 1, 'owner_user_id' => 1, 'name' => 'Forwarder']);
        DB::table('accounting_pantheon_connectors')->insert(['company_id' => 1, 'host' => 'synthetic.invalid', 'database' => 'TEST', 'schema' => 'main', 'username' => 'test',
            'password' => encrypt('unused'), 'allow_write' => true, 'clerk_id' => 45, 'updated_by' => 1]);
        // Synthetic PANTHEON tables in the same in-memory database; no remote system is contacted.
        foreach (['tHE_SetItem' => ['acIdent', 'acName', 'acUM', 'acSetOfItem', 'acVATCode', 'anVAT', 'anSalePrice', 'acCurrency', 'acActive', 'acCode', 'acNote', 'anUserIns', 'anUserChg', 'adTimeIns', 'adTimeChg'],
            'tHE_Stock' => ['acWarehouse', 'acIdent', 'anStock'], 'tHE_SetTax' => ['acVATCode']] as $table => $columns) {
            Schema::create($table, function (Blueprint $t) use ($columns) {
                $t->increments('anQId');
                foreach ($columns as $c) {
                    $t->string($c)->nullable();
                }
            });
        }
        DB::table('tHE_SetTax')->insert([['acVATCode' => 'P1'], ['acVATCode' => 'I0']]);
        DB::table('tHE_SetItem')->insert([
            ['acIdent' => 'TRANSPORT', 'acName' => 'Troškovi transporta', 'acUM' => 'RDS', 'acSetOfItem' => 'USL', 'acVATCode' => 'P1', 'anVAT' => '17', 'anSalePrice' => '0', 'acCurrency' => 'KM', 'acActive' => 'T'],
            ['acIdent' => 'PAKOVANJE', 'acName' => 'Pakovanje', 'acUM' => 'KOM', 'acSetOfItem' => 'OPR', 'acVATCode' => 'P1', 'anVAT' => '17', 'anSalePrice' => '12', 'acCurrency' => 'KM', 'acActive' => 'T'],
            ['acIdent' => 'PART-1', 'acName' => 'Production part', 'acUM' => 'KOM', 'acSetOfItem' => '120', 'acVATCode' => 'P1', 'anVAT' => '17', 'anSalePrice' => '5', 'acCurrency' => 'KM', 'acActive' => 'T']]);
        DB::table('tHE_Stock')->insert([['acWarehouse' => 'Skladište 1', 'acIdent' => 'PAKOVANJE', 'anStock' => '7'], ['acWarehouse' => 'Skladište VP', 'acIdent' => 'PAKOVANJE', 'anStock' => '3']]);
        $this->catalog = new CatalogProducts(new AccountingLedger);
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

    private function sync(): PantheonCatalogSync
    {
        return new PantheonCatalogSync(new class(new AccountingLedger) extends PantheonConnector
        {
            protected function remote(int $companyId): ConnectionInterface
            {
                return DB::connection();
            }
        }, new AccountingLedger);
    }

    public function test_pull_reads_only_chosen_item_sets_with_stock_over_all_warehouses(): void
    {
        $result = $this->sync()->sync(1);
        self::assertSame(2, $result['pulled'], 'Set 120 (production parts) is not part of the default catalogue.');
        $packing = DB::table('catalog_products')->where('code', 'PAKOVANJE')->first();
        self::assertSame(['pantheon', 'service', 'P1', 'BAM'], [$packing->source, $packing->kind, $packing->vat_code, $packing->currency]);
        self::assertEquals(10, (float) $packing->stock);
        self::assertEquals(12, (float) $packing->sale_price);
    }

    public function test_local_product_is_pushed_once_with_vat_and_pantheon_articles_are_never_overwritten(): void
    {
        $this->sync()->sync(1);
        $this->catalog->save(1, 1, ['code' => 'prevoz', 'name' => 'Prevoz FTL', 'unit' => 'KOM', 'sale_price' => '1500', 'vat_percent' => '17']);
        $waiting = $this->sync()->sync(1);
        self::assertSame(['vat_code_required'], $waiting['waiting'][0]['problems']);
        $product = DB::table('catalog_products')->where('code', 'PREVOZ')->first();
        $this->catalog->save(1, 1, ['id' => $product->id, 'code' => 'PREVOZ', 'name' => 'Prevoz FTL', 'unit' => 'KOM', 'sale_price' => '1500', 'vat_percent' => '17', 'vat_code' => 'P1']);
        self::assertSame(['write_disabled'], $this->sync()->sync(1)['waiting'][0]['problems'], 'Push stays off until enabled.');
        DB::table('accounting_pantheon_connectors')->update(['catalog_push_enabled' => true, 'catalog_push_item_set' => 'USL']);
        self::assertSame(['PREVOZ'], $this->sync()->sync(1)['pushed']);
        $remote = DB::table('tHE_SetItem')->where('acIdent', 'PREVOZ')->first();
        self::assertSame(['Prevoz FTL', 'USL', 'P1', 'KM'], [$remote->acName, $remote->acSetOfItem, $remote->acVATCode, $remote->acCurrency]);
        self::assertSame([], $this->sync()->sync(1)['pushed'], 'An unchanged product is not pushed again.');
        // Same code already in PANTHEON (created there): linked, never overwritten.
        $this->catalog->save(1, 1, ['code' => 'TRANSPORT2', 'name' => 'Mine', 'vat_code' => 'P1']);
        DB::table('tHE_SetItem')->insert(['acIdent' => 'TRANSPORT2', 'acName' => 'Theirs', 'acSetOfItem' => 'XXX', 'acActive' => 'T']);
        self::assertSame(['TRANSPORT2'], $this->sync()->sync(1)['linked']);
        self::assertSame('Theirs', DB::table('tHE_SetItem')->where('acIdent', 'TRANSPORT2')->value('acName'));
    }

    public function test_pantheon_article_only_changes_availability_locally(): void
    {
        $this->sync()->sync(1);
        $transport = DB::table('catalog_products')->where('code', 'TRANSPORT')->first();
        $this->catalog->save(1, 1, ['id' => $transport->id, 'name' => 'Renamed', 'active' => false]);
        $after = DB::table('catalog_products')->find($transport->id);
        self::assertSame(['Troškovi transporta', 0], [$after->name, (int) $after->active]);
        self::assertSame([], $this->catalog->search(1, 'Troškovi', null));
    }

    public function test_template_vat_and_product_flow_into_the_work_order(): void
    {
        $this->sync()->sync(1);
        $product = DB::table('catalog_products')->where('code', 'TRANSPORT')->first();
        $template = DB::table('ops_service_templates')->insertGetId(['company_id' => 1, 'code' => 'FTL', 'name' => 'FTL', 'transport_type' => 'road', 'created_by' => 1]);
        DB::table('ops_service_template_items')->insert([
            ['template_id' => $template, 'position' => 1, 'item_type' => 'operation', 'item_code' => 'PAKOVANJE', 'description' => 'Pakovanje', 'unit' => 'KOM', 'planned_qty' => 1, 'planned_price' => 12,
                'product_id' => null, 'vat_percent' => 17, 'vat_code' => 'P1'],
            ['template_id' => $template, 'position' => 2, 'item_type' => 'revenue', 'item_code' => 'TRANSPORT', 'description' => 'Prevoz', 'unit' => 'RDS', 'planned_qty' => 1, 'planned_price' => 1,
                'product_id' => $product->id, 'vat_percent' => 17, 'vat_code' => 'P1']]);
        DB::table('loads')->insert(['id' => 3, 'title' => 'Sarajevo - Graz', 'transport_type' => 'road']);
        ShipmentWorkspace::query()->create(['reference' => 'CAT001', 'load_id' => 3, 'customer_user_id' => 1, 'provider_company_id' => 1, 'provider_user_id' => 1, 'status' => 'booked',
            'currency' => 'BAM', 'agreed_amount' => '900.00', 'load_snapshot' => ['title' => 'Sarajevo - Graz'], 'offer_snapshot' => [], 'parties_snapshot' => [], 'operational_checklist' => [], 'booked_at' => now()]);
        $revenue = DB::table('ops_order_items')->where('item_type', 'revenue')->first();
        self::assertSame(['TRANSPORT', 'P1', (int) $product->id], [$revenue->item_code, $revenue->vat_code, (int) $revenue->product_id]);
        self::assertEquals(900, (float) $revenue->planned_price, 'Revenue keeps the template product and VAT, at the agreed shipment amount.');
        self::assertSame('P1', DB::table('ops_order_items')->where('item_type', 'operation')->value('vat_code'));
    }
}
