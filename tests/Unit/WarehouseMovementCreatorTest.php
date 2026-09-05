<?php

namespace Tests\Unit;

use App\Models\Load;
use App\Models\Offer;
use App\Models\User;
use App\Models\WarehouseMovement;
use App\Services\WarehouseMovementCreator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

class WarehouseMovementCreatorTest extends TestCase
{
    private $app;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \RuntimeException('This test requires explicit SQLite :memory: process settings.');
        }

        $this->app = require __DIR__.'/../../bootstrap/app.php';
        $this->app->afterBootstrapping(LoadConfiguration::class, function ($app): void {
            $app['config']->set('database.default', 'sqlite');
            $app['config']->set('database.connections', ['sqlite' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
        });
        $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        $connection = DB::connection();
        self::assertSame('sqlite', $connection->getConfig('driver'));
        self::assertSame(':memory:', $connection->getConfig('database'));
        self::assertNull($connection->getConfig('host'));
        self::assertNull($connection->getConfig('port'));
        self::assertSame('', $connection->select('PRAGMA database_list')[0]->file);

        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
        });
        // Reuse the real movement schema, without running application migrations or seeders.
        Schema::create('loads', function (Blueprint $table): void {
            $table->id();
        });
        (require __DIR__.'/../../database/migrations/2026_08_26_000006_create_warehouse_movements_table.php')->up();
        DB::table('warehouses')->insert(['id' => 1]);
        DB::table('loads')->insert(['id' => 140]);
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

    private function load(array $attributes = []): Load
    {
        $load = new Load;
        $load->forceFill(array_merge([
            'id' => 140, 'transport_type' => 'warehouse', 'for_storage' => true,
            'title' => 'Storage approval', 'pallets' => 20, 'volume_m3' => 25,
            'weight_kg' => 12000, 'storage_type' => 'Ambient', 'storage_start_date' => '2026-09-10',
        ], $attributes));
        $load->setRelation('customer', new User(['name' => 'Demo Customer']));

        return $load;
    }

    private function offer(array $attributes = []): Offer
    {
        return new Offer(array_merge([
            'warehouse_id' => 1, 'available_from' => '2026-09-16',
            'amount' => 1450, 'currency' => 'EUR', 'status' => 'accepted',
        ], $attributes));
    }

    public function test_storage_approval_schedules_inbound_at_the_accepted_facility_and_date(): void
    {
        $movement = (new WarehouseMovementCreator)->create($this->load(), $this->offer());

        self::assertSame(1, $movement->warehouse_id);
        self::assertSame(140, $movement->load_id);
        self::assertSame('scheduled', $movement->status);
        self::assertSame('inbound', $movement->direction);
        self::assertSame('2026-09-16', $movement->scheduled_at->toDateString());
        self::assertSame(20, $movement->pallets);
        self::assertEquals(25, $movement->cbm);
        self::assertEquals(12000, $movement->weight_kg);
        self::assertEquals(1450, $movement->rate);
        self::assertSame('Demo Customer', $movement->customer_name);
        self::assertNull($movement->completed_at);
    }

    public function test_repeated_creation_preserves_existing_receipt_progress(): void
    {
        $creator = new WarehouseMovementCreator;
        $movement = $creator->create($this->load(), $this->offer());
        $movement->update(['status' => 'completed', 'completed_at' => '2026-09-16 10:00:00', 'dock_number' => 'D2']);
        $again = $creator->create($this->load(), $this->offer());

        self::assertSame($movement->id, $again->id);
        self::assertSame('completed', $again->status);
        self::assertSame('D2', $again->dock_number);
        self::assertSame(1, WarehouseMovement::query()->count());
    }

    public function test_transport_booking_does_not_create_a_dock_movement(): void
    {
        self::assertNull((new WarehouseMovementCreator)->create(
            $this->load(['transport_type' => 'road', 'for_storage' => false]), $this->offer(),
        ));
        self::assertSame(0, WarehouseMovement::query()->count());
    }

    public function test_storage_flag_and_request_date_support_legacy_storage_loads(): void
    {
        $movement = (new WarehouseMovementCreator)->create(
            $this->load(['transport_type' => 'road']), $this->offer(['available_from' => null]),
        );
        self::assertSame('2026-09-10', $movement->scheduled_at->toDateString());
    }

    public function test_invalid_warehouse_prevents_creating_a_movement(): void
    {
        $this->expectException(ValidationException::class);
        (new WarehouseMovementCreator)->create($this->load(), $this->offer(['warehouse_id' => 999]));
    }

    public function test_failed_booking_transaction_leaves_no_dock_movement(): void
    {
        try {
            DB::transaction(function (): void {
                (new WarehouseMovementCreator)->create($this->load(), $this->offer());
                throw new \RuntimeException('Booking failed after scheduling.');
            });
        } catch (\RuntimeException $exception) {
            self::assertSame('Booking failed after scheduling.', $exception->getMessage());
        }
        self::assertSame(0, WarehouseMovement::query()->count());
    }
}
