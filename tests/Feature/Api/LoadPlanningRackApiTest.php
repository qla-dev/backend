<?php

namespace Tests\Feature\Api;

use App\Models\Load;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LoadPlanningRackApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function token(string $username): string
    {
        return $this->postJson('/api/auth/login', ['login' => $username, 'password' => 'demo12345'])->json('data.token');
    }

    public function test_warehouse_rack_pages_net_stock_and_stays_within_the_accounts_facilities(): void
    {
        $owner = User::query()->where('username', 'customer_demo')->firstOrFail();
        $warehouse = Warehouse::query()->create(['user_id' => $owner->id, 'name' => 'Rack Test Depot', 'total_capacity_pallets' => 100]);
        $move = fn (string $customer, string $direction, int $pallets) => WarehouseMovement::query()->create([
            'warehouse_id' => $warehouse->id, 'direction' => $direction, 'status' => 'completed',
            'scheduled_at' => now(), 'completed_at' => now(), 'customer_name' => $customer,
            'storage_type' => 'ambient', 'pallets' => $pallets,
        ]);
        $move('ACME', 'inbound', 10);
        $move('ACME', 'outbound', 4);
        $move('Beta', 'inbound', 3);
        $move('Gamma', 'inbound', 2);
        $move('Gamma', 'outbound', 2);

        $token = $this->token('customer_demo');
        $first = $this->withToken($token)->getJson('/api/load-planning/racks/warehouse?per_page=1&page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.items.0.customer_name', 'ACME')
            ->assertJsonPath('data.items.0.pallets', 6)
            ->assertJsonPath('data.items.0.warehouse_name', 'Rack Test Depot');
        $this->assertSame(9, collect($first->json('data.warehouses'))->firstWhere('name', 'Rack Test Depot')['occupied_pallets']);

        $this->withToken($token)->getJson('/api/load-planning/racks/warehouse?per_page=1&page=2')
            ->assertOk()
            ->assertJsonPath('data.items.0.customer_name', 'Beta')
            ->assertJsonPath('data.items.0.pallets', 3);

        $other = $this->withToken($this->token('warehouse_demo'))->getJson('/api/load-planning/racks/warehouse?per_page=100')->assertOk();
        $this->assertNotContains('Rack Test Depot', collect($other->json('data.warehouses'))->pluck('name'));
        $this->assertNotContains($warehouse->id, collect($other->json('data.items'))->pluck('warehouse_id'));
    }

    public function test_tracking_rack_lists_only_booked_loads_the_account_may_see(): void
    {
        $owner = User::query()->where('username', 'customer_demo')->firstOrFail();
        $load = fn (string $title, string $status) => Load::query()->create([
            'public_id' => (string) Str::uuid(), 'customer_user_id' => $owner->id, 'title' => $title,
            'status' => $status, 'transport_type' => 'road', 'cargo_type' => 'ltl', 'weight_kg' => 1200, 'pallets' => 4,
        ]);
        $load('Rack test load', 'booked');
        $load('Rack load on the road', 'in_delivery');

        $mine = $this->withToken($this->token('customer_demo'))->getJson('/api/load-planning/racks/tracking?per_page=100')
            ->assertOk()
            ->assertJsonStructure(['data', 'meta' => ['current_page', 'last_page', 'per_page', 'total']]);
        $row = collect($mine->json('data'))->firstWhere('title', 'Rack test load');
        $this->assertNotNull($row);
        $this->assertSame(4, $row['pallets']);
        $this->assertNotContains('Rack load on the road', collect($mine->json('data'))->pluck('title'));
        $this->assertEmpty(collect($mine->json('data'))->whereNotIn('status', ['booked', 'sent']));

        $theirs = $this->withToken($this->token('finance_demo'))->getJson('/api/load-planning/racks/tracking?per_page=100')->assertOk();
        $this->assertNotContains('Rack test load', collect($theirs->json('data'))->pluck('title'));
    }
}
