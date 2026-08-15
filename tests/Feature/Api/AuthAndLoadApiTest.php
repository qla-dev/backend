<?php

namespace Tests\Feature\Api;

use App\Models\Load;
use App\Models\LoadStop;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
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

    public function test_local_frontend_login_uses_bearer_auth_without_csrf(): void
    {
        $this->withHeaders(['Origin' => 'http://localhost:3000'])
            ->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])
            ->assertOk()
            ->assertJsonPath('data.user.role.name', 'superadmin')
            ->assertJsonStructure(['data' => ['token']]);
    }

    public function test_seeder_creates_all_five_accounts_with_superadmin_first(): void
    {
        $this->assertSame('superadmin', Role::query()->orderBy('id')->value('name'));
        $this->assertSame([
            'superadmin_demo',
            'customer_demo',
            'driver_demo',
            'company_demo',
            'finance_demo',
        ], User::query()->orderBy('id')->pluck('username')->all());
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

    public function test_superadmin_can_approve_an_offer_and_assign_its_driver(): void
    {
        $load = Load::query()->firstOrFail();
        $driver = User::query()->where('username', 'driver_demo')->firstOrFail();
        $offer = Offer::query()->create([
            'load_id' => $load->id,
            'company_id' => $load->company_id,
            'driver_user_id' => $driver->id,
            'created_by_user_id' => $driver->id,
            'amount' => 1250,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        $this->withToken($token)->postJson("/api/offers/{$offer->id}/approve", [
            'driver_user_id' => $driver->id,
        ])->assertOk()->assertJsonPath('data.status', 'accepted');

        $this->assertDatabaseHas('loads', ['id' => $load->id, 'assigned_driver_user_id' => $driver->id, 'status' => 'assigned']);
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'accepted']);
    }

    public function test_superadmin_can_manually_create_a_customer_account(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/users/customer', [
            'name' => 'Manual Customer', 'email' => 'manual.customer@example.com',
            'username' => 'manual_customer', 'password' => 'secure-pass-123',
            'country_code' => 'DE', 'language' => 'de',
        ])->assertCreated()->assertJsonPath('data.role.name', 'user');

        $this->assertDatabaseHas('users', ['id' => $response->json('data.id'), 'username' => 'manual_customer', 'is_active' => true]);
    }

    public function test_superadmin_can_onboard_a_company_with_owner_and_membership(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/companies/onboard', [
            'company_name' => 'Manual Logistics GmbH', 'company_email' => 'office@manual-logistics.example',
            'country_code' => 'DE', 'city' => 'Berlin', 'plan' => 'growth', 'status' => 'verified',
            'owner_name' => 'Manual Owner', 'owner_email' => 'owner@manual-logistics.example',
            'owner_username' => 'manual_company_owner', 'owner_password' => 'secure-pass-123',
        ])->assertCreated()->assertJsonPath('data.name', 'Manual Logistics GmbH');

        $companyId = $response->json('data.id');
        $owner = User::query()->where('username', 'manual_company_owner')->firstOrFail();
        $this->assertSame('company', $owner->role->name);
        $this->assertDatabaseHas('companies', ['id' => $companyId, 'owner_user_id' => $owner->id]);
        $this->assertDatabaseHas('company_user', ['company_id' => $companyId, 'user_id' => $owner->id, 'company_role' => 'admin', 'status' => 'active']);
    }

    public function test_superadmin_can_create_a_driver_with_profile_and_company_membership(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');
        $company = \App\Models\Company::query()->firstOrFail();

        $response = $this->withToken($token)->postJson('/api/users/driver', [
            'name' => 'Manual Driver', 'email' => 'manual.driver@example.com',
            'username' => 'manual_driver', 'password' => 'secure-pass-123',
            'country_code' => 'BA', 'language' => 'bs', 'primary_company_id' => $company->id,
            'license_number' => 'BA-MANUAL-DRIVER', 'license_country_code' => 'BA',
            'license_expires_at' => now()->addYears(2)->toDateString(), 'availability_status' => 'available',
        ])->assertCreated()
            ->assertJsonPath('data.user.role.name', 'driver')
            ->assertJsonPath('data.primary_company.id', $company->id);

        $userId = $response->json('data.user.id');
        $this->assertDatabaseHas('users', ['id' => $userId, 'username' => 'manual_driver', 'is_active' => true]);
        $this->assertDatabaseHas('driver_profiles', ['user_id' => $userId, 'primary_company_id' => $company->id, 'license_number' => 'BA-MANUAL-DRIVER']);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $userId, 'company_role' => 'driver', 'status' => 'active']);
    }
}
