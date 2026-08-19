<?php

namespace Tests\Feature\Api;

use App\Mail\CustomerFirstPasswordMail;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Load;
use App\Models\LoadStop;
use App\Models\Offer;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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

    public function test_authenticated_user_can_open_shipment_payment_documents(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');
        $shipment = Shipment::query()->firstOrFail();

        $this->withToken($token)
            ->get("/api/shipments/{$shipment->id}/invoice/predracun")
            ->assertOk()
            ->assertSee('Predračun')
            ->assertSee($shipment->tracking_number)
            ->assertSee('Preuzmi PDF');

        $this->withToken($token)
            ->get("/api/shipments/{$shipment->id}/invoice/a4-faktura")
            ->assertOk()
            ->assertSee('A4 faktura');
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
            ->putJson("/api/loads/{$loadId}", ['title' => 'Updated cold-chain cargo'])
            ->assertOk()
            ->assertJsonPath('data.title', 'Updated cold-chain cargo');

        $this->withToken($token)
            ->deleteJson("/api/loads/{$loadId}")
            ->assertOk();

        $this->assertDatabaseMissing('loads', ['id' => $loadId]);
        $this->assertDatabaseMissing('load_stops', ['load_id' => $loadId]);
    }

    public function test_authenticated_customer_options_use_remote_search_and_load_more_pagination(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');

        foreach (range(1, 25) as $number) {
            Customer::query()->create([
                'name' => sprintf('Paged Consignee %02d', $number),
                'customer_type' => 'business',
                'status' => 'active',
            ]);
        }

        $this->withToken($token)
            ->getJson('/api/customer-options?search=Paged%20Consignee&limit=20&pageno=1')
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.page_no', 1)
            ->assertJsonPath('meta.has_more', true)
            ->assertJsonStructure(['data' => [['id', 'text', 'name', 'tax_number', 'country_code', 'city', 'address', 'source']]]);

        $this->withToken($token)
            ->getJson('/api/customer-options?search=Paged%20Consignee&limit=20&pageno=2')
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('meta.page_no', 2)
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_new_load_can_reference_a_standalone_customer_as_consignee(): void
    {
        $token = $this->postJson('/api/auth/login', [
            'login' => 'customer_demo',
            'password' => 'demo12345',
        ])->json('data.token');
        $consignee = Customer::query()->create([
            'name' => 'Global Standalone Consignee',
            'customer_type' => 'business',
            'status' => 'active',
        ]);

        $response = $this->withToken($token)->postJson('/api/loads', [
            'consignee_customer_id' => $consignee->id,
            'title' => 'Load with global consignee',
            'cargo_type' => 'FTL',
            'weight_kg' => 12000,
        ])->assertCreated()
            ->assertJsonPath('data.consignee.id', $consignee->id)
            ->assertJsonPath('data.consignee.name', 'Global Standalone Consignee');

        $this->assertDatabaseHas('loads', [
            'id' => $response->json('data.id'),
            'consignee_customer_id' => $consignee->id,
        ]);
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
                'email' => 'customer@freightbook.test',
                'fax' => '',
            ],
            'notes' => 'Created through the post-load wizard.',
            'internal_comments' => null,
            'external_comments' => null,
            'status' => 'posted',
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
            ->assertJsonPath('data.status', 'posted')
            ->assertJsonPath('data.weight_kg', 24000)
            ->assertJsonPath('data.contact.name', 'Current user')
            ->assertJsonCount(2, 'data.stops');

        $loadId = $response->json('data.id');
        $this->assertDatabaseHas('loads', [
            'id' => $loadId,
            'customer_user_id' => User::query()->where('username', 'customer_demo')->value('id'),
            'weight_kg' => 24000,
            'status' => 'posted',
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

        $postedLoad = Load::query()->create([
            'customer_user_id' => User::query()->where('username', 'customer_demo')->value('id'),
            'public_id' => '00000000-0000-4000-8000-000000000099',
            'title' => 'Posted exchange cargo',
            'status' => 'posted',
            'transport_type' => 'road',
            'cargo_type' => 'FTL',
            'weight_kg' => 1000,
            'currency' => 'EUR',
        ]);

        $response = $this->withToken($token)
            ->getJson('/api/loads?status=posted&limit=100')
            ->assertOk();

        $this->assertSame([$postedLoad->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertNotContains('in_delivery', collect($response->json('data'))->pluck('status')->all());
        $this->assertNotContains('sent', collect($response->json('data'))->pluck('status')->all());
    }

    public function test_only_customers_and_superadmin_can_book_a_load(): void
    {
        $bookingPayload = [
            'title' => 'Attempted booking', 'cargo_type' => 'FTL', 'weight_kg' => 1000,
        ];

        $driverToken = $this->postJson('/api/auth/login', ['login' => 'driver_demo', 'password' => 'demo12345'])->json('data.token');
        $this->withToken($driverToken)->postJson('/api/loads', $bookingPayload)->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $companyToken = $this->postJson('/api/auth/login', ['login' => 'company_demo', 'password' => 'demo12345'])->json('data.token');
        $this->withToken($companyToken)->postJson('/api/loads', $bookingPayload)->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $superadminToken = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');
        $this->withToken($superadminToken)->postJson('/api/loads', $bookingPayload)->assertCreated();
    }

    public function test_customer_cannot_book_a_load_under_another_customers_identity(): void
    {
        $otherCustomer = User::query()->create([
            'role_id' => \App\Models\Role::query()->where('name', 'user')->value('id'),
            'name' => 'Other Customer', 'email' => 'other.customer@example.com', 'username' => 'other_customer',
            'password' => Hash::make('secure-pass-123'), 'is_active' => true,
        ]);
        $token = $this->postJson('/api/auth/login', ['login' => 'customer_demo', 'password' => 'demo12345'])->json('data.token');

        $response = $this->withToken($token)->postJson('/api/loads', [
            'customer_user_id' => $otherCustomer->id,
            'title' => 'Spoofed booking', 'cargo_type' => 'FTL', 'weight_kg' => 1000,
        ])->assertCreated();

        $this->assertDatabaseHas('loads', [
            'id' => $response->json('data.id'),
            'customer_user_id' => User::query()->where('username', 'customer_demo')->value('id'),
        ]);
    }

    public function test_customer_only_sees_their_own_loads_in_the_listing(): void
    {
        $otherCustomer = User::query()->create([
            'role_id' => \App\Models\Role::query()->where('name', 'user')->value('id'),
            'name' => 'Other Customer', 'email' => 'other.customer2@example.com', 'username' => 'other_customer2',
            'password' => Hash::make('secure-pass-123'), 'is_active' => true,
        ]);
        Load::query()->create([
            'customer_user_id' => $otherCustomer->id,
            'public_id' => '00000000-0000-4000-8000-000000000098',
            'title' => 'Someone elses cargo', 'status' => 'pending', 'transport_type' => 'road',
            'cargo_type' => 'FTL', 'weight_kg' => 500, 'currency' => 'EUR',
        ]);

        $token = $this->postJson('/api/auth/login', ['login' => 'customer_demo', 'password' => 'demo12345'])->json('data.token');
        $response = $this->withToken($token)->getJson('/api/loads?limit=500')->assertOk();

        $this->assertNotContains('Someone elses cargo', collect($response->json('data'))->pluck('title')->all());
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
            ->assertJsonPath('data.0.name', 'Freightbook Logistics Hub');
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

        $this->assertDatabaseHas('loads', ['id' => $load->id, 'assigned_driver_user_id' => $driver->id, 'status' => 'sent']);
        $this->assertDatabaseHas('offers', ['id' => $offer->id, 'status' => 'accepted']);
    }

    public function test_only_superadmin_can_change_load_status_and_timestamp_is_recorded(): void
    {
        $load = Load::query()->firstOrFail();
        $customerToken = $this->postJson('/api/auth/login', ['login' => 'customer_demo', 'password' => 'demo12345'])->json('data.token');

        $this->withToken($customerToken)
            ->patchJson("/api/loads/{$load->id}/status", ['status' => 'opened'])
            ->assertForbidden();

        $this->flushHeaders();
        $this->app['auth']->forgetGuards();
        $superadminToken = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');
        $response = $this->withToken($superadminToken)
            ->patchJson("/api/loads/{$load->id}/status", ['status' => 'opened'])
            ->assertOk()
            ->assertJsonPath('data.status', 'opened');

        $this->assertNotEmpty($response->json('data.status_change.opened'));
        $this->assertDatabaseHas('loads', ['id' => $load->id, 'status' => 'opened']);
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

    public function test_superadmin_can_authorize_a_standalone_customer_and_send_first_password(): void
    {
        Mail::fake();
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');
        $customer = Customer::query()->create([
            'name' => 'Imported Customer',
            'email' => 'old-address@example.com',
            'customer_type' => 'business',
            'status' => 'active',
        ]);

        $this->withToken($token)
            ->postJson("/api/customers/{$customer->id}/authorize", [
                'email' => 'authorized.customer@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.user.email', 'authorized.customer@example.com')
            ->assertJsonPath('meta.email_sent', true);

        $customer->refresh();
        $this->assertNotNull($customer->user_id);
        $this->assertNotNull($customer->profile_authorized_at);
        $this->assertTrue($customer->user->is_active);

        $temporaryPassword = null;
        Mail::assertSent(CustomerFirstPasswordMail::class, function (CustomerFirstPasswordMail $mail) use (&$temporaryPassword): bool {
            $user = User::query()->where('email', 'authorized.customer@example.com')->firstOrFail();
            $temporaryPassword = $mail->temporaryPassword;

            return $mail->hasTo('authorized.customer@example.com')
                && $mail->username === $user->username
                && Hash::check($mail->temporaryPassword, $user->password);
        });

        $this->postJson('/api/auth/login', [
            'login' => $customer->user->username,
            'password' => $temporaryPassword,
        ])->assertOk()->assertJsonPath('data.user.id', $customer->user_id);
    }

    public function test_customer_authorization_requires_a_valid_unique_email(): void
    {
        Mail::fake();
        $token = $this->postJson('/api/auth/login', ['login' => 'superadmin_demo', 'password' => 'demo12345'])->json('data.token');
        $customer = Customer::query()->create([
            'name' => 'Imported Customer',
            'customer_type' => 'business',
            'status' => 'active',
        ]);

        $this->withToken($token)
            ->postJson("/api/customers/{$customer->id}/authorize", ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->withToken($token)
            ->postJson("/api/customers/{$customer->id}/authorize", ['email' => 'customer_demo@freightbook.test'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->assertNull($customer->fresh()->user_id);
        Mail::assertNothingSent();
    }

    public function test_only_superadmin_can_authorize_a_customer(): void
    {
        Mail::fake();
        $token = $this->postJson('/api/auth/login', ['login' => 'customer_demo', 'password' => 'demo12345'])->json('data.token');
        $customer = Customer::query()->create([
            'name' => 'Imported Customer',
            'customer_type' => 'business',
            'status' => 'active',
        ]);

        $this->withToken($token)
            ->postJson("/api/customers/{$customer->id}/authorize", ['email' => 'imported@example.com'])
            ->assertForbidden();

        $this->assertNull($customer->fresh()->user_id);
        Mail::assertNothingSent();
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
