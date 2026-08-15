<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
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

    public function test_driver_registration_creates_a_separate_driver_record(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'role' => 'driver',
            'name' => 'Registered Driver',
            'email' => 'registered.driver@example.com',
            'username' => 'registered_driver',
            'password' => 'secure-pass-123',
            'language' => 'en',
            'license_number' => 'BA-REGISTERED-001',
            'license_country_code' => 'BA',
            'license_expires_at' => now()->addYear()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.user.role.name', 'driver')
            ->assertJsonPath('data.user.driver.license_number', 'BA-REGISTERED-001');

        $this->assertDatabaseHas('drivers', [
            'user_id' => $response->json('data.user.id'),
            'license_number' => 'BA-REGISTERED-001',
        ]);
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
        $this->assertSame('customer_demo', Customer::query()->firstOrFail()->user->username);
        $this->assertSame('driver_demo', Driver::query()->firstOrFail()->user->username);
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

    public function test_post_load_modal_payload_creates_a_publishable_load(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/loads', [
            'title' => 'Full modal flow cargo',
            'transport_type' => 'road',
            'cargo_type' => 'FTL',
            'goods_type' => 'General',
            'weight_kg' => 24000,
            'length_m' => 13.6,
            'volume_m3' => 82,
            'pallets' => 24,
            'declared_value' => 50000,
            'budget' => 1450,
            'currency' => 'EUR',
            'payment_terms' => 'negotiable',
            'payment_due_days' => 30,
            'requires_adr' => false,
            'requires_tail_lift' => false,
            'must_be_trackable' => true,
            'is_urgent' => false,
            'body_types' => ['Curtain'],
            'contact' => [
                'name' => 'Current user',
                'phone' => '+38733123456',
                'mobile' => '',
                'email' => 'customer@smartfreight.test',
                'fax' => '',
            ],
            'notes' => 'Created through the post-load wizard.',
            'internal_comments' => null,
            'external_comments' => null,
            'status' => 'available',
            'published_at' => '2026-08-15T12:00:00Z',
            'stops' => [
                [
                    'type' => 'pickup', 'position' => 1, 'place_type' => 'Loading place',
                    'city' => 'Sarajevo', 'country_code' => 'BA', 'address' => 'Warehouse 1',
                    'window_starts_at' => '2026-08-20T08:00:00', 'window_ends_at' => '2026-08-20T10:00:00',
                ],
                [
                    'type' => 'delivery', 'position' => 2, 'place_type' => 'Unloading place',
                    'city' => 'Berlin', 'country_code' => 'DE', 'address' => 'Terminal 2',
                    'window_starts_at' => '2026-08-22T08:00:00', 'window_ends_at' => '2026-08-22T12:00:00',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Full modal flow cargo')
            ->assertJsonPath('data.status', 'available')
            ->assertJsonPath('data.weight_kg', 24000)
            ->assertJsonPath('data.contact.name', 'Current user')
            ->assertJsonCount(2, 'data.stops');

        $loadId = $response->json('data.id');
        $this->assertDatabaseHas('loads', [
            'id' => $loadId,
            'customer_user_id' => User::query()->where('username', 'customer_demo')->value('id'),
            'weight_kg' => 24000,
            'status' => 'available',
        ]);
        $this->assertDatabaseHas('load_stops', [
            'load_id' => $loadId,
            'type' => 'delivery',
            'city' => 'Berlin',
            'position' => 2,
        ]);
    }

    public function test_load_listing_can_be_limited_to_freight_exchange_loads(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');

        $availableLoad = Load::query()->create([
            'customer_user_id' => User::query()->where('username', 'customer_demo')->value('id'),
            'public_id' => '00000000-0000-4000-8000-000000000099',
            'title' => 'Available exchange cargo',
            'status' => 'available',
            'transport_type' => 'road',
            'cargo_type' => 'FTL',
            'weight_kg' => 1000,
            'currency' => 'EUR',
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/loads?status=available&limit=100')
            ->assertOk();

        $this->assertSame([$availableLoad->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains('in_transit', collect($response->json('data'))->pluck('status')->all());
        $this->assertNotContains('assigned', collect($response->json('data'))->pluck('status')->all());
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

        $response = $this->withToken($token)->postJson('/api/customers', [
            'name' => 'Manual Customer', 'email' => 'manual.customer@example.com',
            'username' => 'manual_customer', 'password' => 'secure-pass-123',
            'country_code' => 'DE', 'language' => 'de',
        ])->assertCreated()->assertJsonPath('data.user.role.name', 'user');

        $userId = $response->json('data.user.id');
        $this->assertDatabaseHas('users', ['id' => $userId, 'username' => 'manual_customer', 'is_active' => true]);
        $this->assertDatabaseHas('customers', ['id' => $response->json('data.id'), 'user_id' => $userId, 'status' => 'active']);
    }

    public function test_superadmin_can_create_a_customer_without_a_user_account(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/customers', [
            'name' => 'Standalone Customer',
            'email' => 'standalone.customer@example.com',
            'phone' => '+38761111222',
            'country_code' => 'BA',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Standalone Customer')
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('data.id'),
            'user_id' => null,
            'profile_authorized_at' => null,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'standalone.customer@example.com']);
    }

    public function test_customer_listing_supports_server_side_search_limit_and_page_number(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        $this->withToken($token)->postJson('/api/customers', [
            'name' => 'Pagination Customer', 'email' => 'pagination.customer@example.com',
            'username' => 'pagination_customer', 'password' => 'secure-pass-123',
            'country_code' => 'BA', 'language' => 'bs',
        ])->assertCreated();

        $this->withToken($token)
            ->getJson('/api/customers?search=customer&limit=1&pageno=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user.role.name', 'user')
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.page_no', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.limit', 1)
            ->assertJsonPath('meta.total', 2);
    }

    public function test_customer_listing_uses_deklarant_name_order_by_default(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        Customer::query()->create(['name' => 'Zulu Logistics', 'customer_type' => 'business', 'status' => 'active']);
        Customer::query()->create(['name' => '059 d.o.o. Bileća', 'customer_type' => 'business', 'status' => 'active']);

        $response = $this->withToken($token)->getJson('/api/customers?limit=50&pageno=1')->assertOk();

        $names = collect($response->json('data'))->pluck('name')->all();
        $sortedNames = $names;
        sort($sortedNames, SORT_NATURAL | SORT_FLAG_CASE);

        $this->assertSame($sortedNames, $names);
        $this->assertSame('059 d.o.o. Bileća', $names[0]);
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
        $company = Company::query()->firstOrFail();

        $response = $this->withToken($token)->postJson('/api/drivers', [
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
        $this->assertDatabaseHas('drivers', ['user_id' => $userId, 'primary_company_id' => $company->id, 'license_number' => 'BA-MANUAL-DRIVER']);
        $this->assertDatabaseHas('company_user', ['company_id' => $company->id, 'user_id' => $userId, 'company_role' => 'driver', 'status' => 'active']);
    }

    public function test_superadmin_can_create_a_driver_without_a_user_account(): void
    {
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/drivers', [
            'name' => 'Standalone Driver',
            'email' => 'standalone.driver@example.com',
            'license_number' => 'BA-STANDALONE-001',
            'license_country_code' => 'BA',
            'license_expires_at' => now()->addYear()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Standalone Driver')
            ->assertJsonPath('data.user_id', null)
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('drivers', [
            'id' => $response->json('data.id'),
            'user_id' => null,
            'profile_authorized_at' => null,
        ]);
        $this->assertDatabaseMissing('users', ['email' => 'standalone.driver@example.com']);
    }
}
