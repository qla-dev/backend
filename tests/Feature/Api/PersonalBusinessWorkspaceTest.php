<?php

namespace Tests\Feature\Api;

use App\Models\Company;
use App\Models\Role;
use App\Services\Accounting\BusinessWorkspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PersonalBusinessWorkspaceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $connection = DB::connection();
        self::assertSame('sqlite', $connection->getDriverName());
        self::assertSame(':memory:', $connection->getDatabaseName());
        self::assertSame('', $connection->selectOne('PRAGMA database_list')->file);
        $this->artisan('migrate', ['--no-interaction' => true])->assertSuccessful();
        Role::query()->create(['name' => 'company', 'label' => 'Company']);
    }

    public function test_registration_opens_private_business_modules_without_a_company_membership(): void
    {
        [$owner, $token] = $this->registerAccount('owner');
        [, $otherToken] = $this->registerAccount('other');
        $id = BusinessWorkspace::personalId($owner);
        $base = "/api/accounting/{$id}";

        $this->withToken($token)->getJson('/api/accounting/companies')->assertExactJson([
            'data' => [], 'message' => 'Accounting operation completed.', 'errors' => [], 'meta' => [],
        ]);
        $this->getJson('/api/accounting/workspaces')->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("{$base}/context")->assertOk()->assertJsonPath('data.personal', true);
        foreach (['overview', 'crm', 'catalog', 'ops'] as $module) {
            $this->getJson("{$base}/{$module}")->assertOk();
        }
        $this->assertDatabaseCount('business_workspaces', 0);

        $this->postJson("{$base}/settings", [
            'jurisdiction' => 'FBiH', 'base_currency' => 'BAM', 'invoice_prefix' => 'INV',
        ])->assertOk();
        $this->postJson("{$base}/invoices", [
            'direction' => 'outgoing', 'partner_name' => 'Example customer',
            'items' => [['description' => 'Service', 'quantity' => 1, 'unit_price' => 10]],
        ])->assertCreated()->assertJsonPath('data.company_id', $id);
        $this->postJson("{$base}/crm/documents", [
            'stage' => 'lead', 'title' => 'New inquiry', 'customer_name' => 'Example customer', 'currency' => 'BAM',
        ])->assertOk();
        $this->postJson("{$base}/catalog", ['code' => 'SERVICE', 'name' => 'Service'])->assertOk();
        $this->postJson("{$base}/ops/orders", [
            'title' => 'New order', 'customer_name' => 'Example customer', 'department' => 'road',
        ])->assertCreated();
        $this->getJson("{$base}/country-profile")->assertNotFound();
        $this->postJson("{$base}/pos/reports/daily")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson("{$base}/crm")->assertForbidden();
        $this->postJson("{$base}/catalog", ['code' => 'OTHER', 'name' => 'Other'])->assertForbidden();

        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('company_user', 0);
        $this->assertDatabaseHas('business_workspaces', ['id' => $id, 'user_id' => $owner, 'company_id' => null]);
    }

    public function test_existing_company_workspace_and_verification_use_the_company_id(): void
    {
        [$owner, $token] = $this->registerAccount('owner');
        $company = Company::query()->create(['owner_user_id' => $owner, 'name' => 'Example',
            'slug' => 'example', 'country_code' => 'BA']);

        $this->assertDatabaseHas('business_workspaces', ['id' => $company->id, 'company_id' => $company->id]);
        $this->withToken($token)->getJson('/api/accounting/workspaces')->assertOk()
            ->assertJsonPath('data.0.id', BusinessWorkspace::personalId($owner))
            ->assertJsonPath('data.1.id', $company->id);
        $this->getJson("/api/accounting/{$company->id}/country-profile")->assertOk();
    }

    private function registerAccount(string $username): array
    {
        $result = $this->postJson('/api/auth/register', [
            'role' => 'company', 'name' => $username, 'username' => $username,
            'email' => "{$username}@example.test", 'password' => 'fixture-password',
        ])->assertCreated();

        return [$result->json('data.user.id'), $result->json('data.token')];
    }
}
