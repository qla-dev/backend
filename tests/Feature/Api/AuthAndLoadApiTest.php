<?php

namespace Tests\Feature\Api;

use App\Models\Load;
use App\Models\LoadStop;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthAndLoadApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_demo_user_can_login_and_read_the_authenticated_profile(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->assertOk()
            ->assertJsonPath('data.user.username', 'customer_demo')
            ->assertJsonPath('data.user.role.name', 'user');

        $token = $login->json('data.token');

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.username', 'customer_demo');
    }

    public function test_authenticated_user_can_create_update_and_delete_a_load_with_stops(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/loads', [
            'title' => 'API connected cargo',
            'cargo_type' => 'FTL',
            'transport_type' => 'road',
            'weight_kg' => 12500,
            'currency' => 'EUR',
            'stops' => [
                ['type' => 'pickup', 'position' => 1, 'city' => 'Sarajevo', 'country_code' => 'BA'],
                ['type' => 'delivery', 'position' => 2, 'city' => 'Hamburg', 'country_code' => 'DE'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'API connected cargo')
            ->assertJsonCount(2, 'data.stops');

        $loadId = $response->json('data.id');
        $this->assertDatabaseHas('loads', ['id' => $loadId, 'title' => 'API connected cargo']);
        $this->assertDatabaseCount('load_stops', 4);

        $this->withToken($token)
            ->putJson("/api/loads/{$loadId}", ['status' => 'booked'])
            ->assertOk()
            ->assertJsonPath('data.status', 'booked');

        $this->withToken($token)
            ->deleteJson("/api/loads/{$loadId}")
            ->assertOk();

        $this->assertDatabaseMissing('loads', ['id' => $loadId]);
        $this->assertDatabaseMissing('load_stops', ['load_id' => $loadId]);
    }

    public function test_invalid_foreign_key_is_rejected_before_insert(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');

        $this->withToken($token)->postJson('/api/loads', [
            'customer_user_id' => 999999,
            'title' => 'Invalid owner',
            'cargo_type' => 'FTL',
            'weight_kg' => 1000,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('customer_user_id');
    }

    public function test_database_foreign_keys_cascade_load_children(): void
    {
        $load = Load::query()->where('title', 'Pharma Temperature Cargo')->firstOrFail();
        $this->assertSame(2, $load->stops()->count());

        $loadId = $load->id;
        $load->delete();

        $this->assertSame(0, LoadStop::query()->where('load_id', $loadId)->count());
    }

    public function test_customer_cannot_access_superadmin_resources(): void
    {
        $customerToken = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');

        $this->withToken($customerToken)->getJson('/api/companies')->assertForbidden();
    }

    public function test_superadmin_can_access_global_company_registry(): void
    {
        $adminLogin = $this->postJson('/api/auth/login', [
            'login' => 'superadmin_demo',
            'password' => 'demo12345',
        ])->assertOk()->assertJsonPath('data.user.role.name', 'superadmin');
        $adminToken = $adminLogin->json('data.token');

        $this->withToken($adminToken)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.role.name', 'superadmin');

        $this->withToken($adminToken)
            ->getJson('/api/companies')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Smartfreight Logistics Hub');
    }
}
