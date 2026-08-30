<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Driver;
use App\Models\Load;
use App\Models\Role;
use App\Models\SubscriptionPackage;
use App\Models\User;
use App\Models\UserSubscription;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Models\WarehouseMovement;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $roleLabels = ['master' => 'Master', 'superadmin' => 'Superadmin', 'user' => 'Customer', 'driver' => 'Driver', 'company' => 'Logistics Company', 'finance' => 'Finance & Administration', 'warehouse' => 'Warehouse Company', 'guest' => 'Guest', 'system' => 'AI Assistant'];
        $roles = collect($roleLabels)->mapWithKeys(fn (string $label, string $name) => [$name => Role::query()->updateOrCreate(['name' => $name], ['label' => $label, 'permissions' => in_array($name, Role::PROTECTED_NAMES, true) ? ['*'] : [], 'is_active' => ! in_array($name, ['guest', 'system'], true)])]);

        $accounts = [
            'master_demo' => ['role' => 'master', 'name' => 'Master Admin'],
            'superadmin_demo' => ['role' => 'superadmin', 'name' => 'John Doe'],
            'customer_demo' => ['role' => 'user', 'name' => 'Demo Customer'],
            'driver_demo' => ['role' => 'driver', 'name' => 'Demo Driver'],
            'company_demo' => ['role' => 'company', 'name' => 'Freightbook Logistics Hub'],
            'finance_demo' => ['role' => 'finance', 'name' => 'Demo Finance'],
            'warehouse_demo' => ['role' => 'company', 'name' => 'Freightbook Warehousing Co.'],
            'guest_demo' => ['role' => 'guest', 'name' => 'Guest'],
            'ai_dispatcher' => ['role' => 'system', 'name' => 'AI Dispatcher'],
        ];

        $users = collect($accounts)->mapWithKeys(function (array $account, string $username) use ($roles) {
            $user = User::query()->updateOrCreate(['username' => $username], [
                'role_id' => $roles[$account['role']]->id, 'name' => $account['name'], 'email' => "{$username}@freightbook.test",
                'password' => 'demo12345', 'language' => 'bs', 'country_code' => 'BA', 'is_active' => $account['role'] !== 'system', 'email_verified_at' => now(),
            ]);

            return [$username => $user];
        });

        Customer::query()->updateOrCreate(
            ['user_id' => $users['customer_demo']->id],
            [
                'name' => $users['customer_demo']->name,
                'email' => $users['customer_demo']->email,
                'customer_type' => 'private',
                'status' => 'active',
                'profile_authorized_at' => now(),
            ]
        );

        $company = Company::query()->updateOrCreate(['owner_user_id' => $users['company_demo']->id], [
            'owner_user_id' => $users['company_demo']->id, 'name' => 'Freightbook Logistics Hub', 'email' => 'company_demo@freightbook.test',
            'slug' => 'freightbook-logistics-hub', 'country_code' => 'BA', 'city' => 'Sarajevo', 'plan' => 'enterprise', 'status' => 'verified', 'verified_at' => now(),
        ]);
        $company->users()->syncWithoutDetaching([
            $users['company_demo']->id => ['company_role' => 'admin', 'status' => 'active', 'joined_at' => now()],
            $users['driver_demo']->id => ['company_role' => 'driver', 'status' => 'active', 'invited_by_user_id' => $users['company_demo']->id, 'joined_at' => now()],
            $users['finance_demo']->id => ['company_role' => 'finance', 'status' => 'active', 'invited_by_user_id' => $users['company_demo']->id, 'joined_at' => now()],
        ]);

        Driver::query()->updateOrCreate(['user_id' => $users['driver_demo']->id], ['name' => $users['driver_demo']->name, 'email' => $users['driver_demo']->email, 'profile_authorized_at' => now(), 'primary_company_id' => $company->id, 'license_number' => 'BA-DEMO-001', 'license_country_code' => 'BA', 'license_expires_at' => now()->addYears(3), 'availability_status' => 'available', 'rating' => 4.90]);
        $vehicle = Vehicle::query()->updateOrCreate(['registration_number' => 'DEMO-001'], ['company_id' => $company->id, 'owner_user_id' => $users['company_demo']->id, 'assigned_driver_user_id' => $users['driver_demo']->id, 'transport_type' => 'road', 'vehicle_type' => 'Truck', 'make' => 'Mercedes-Benz', 'model' => 'Actros', 'year' => 2025, 'capacity_kg' => 24000, 'status' => 'active']);
        $load = Load::query()->updateOrCreate(['public_id' => '00000000-0000-4000-8000-000000000001'], ['customer_user_id' => $users['customer_demo']->id, 'company_id' => $company->id, 'assigned_driver_user_id' => $users['driver_demo']->id, 'vehicle_id' => $vehicle->id, 'title' => 'Pharma Temperature Cargo', 'status' => 'in_delivery', 'transport_type' => 'road', 'cargo_type' => 'FTL', 'goods_type' => 'Pharma', 'weight_kg' => 11200, 'budget' => 1480, 'currency' => 'EUR', 'payment_terms' => 'on_delivery', 'must_be_trackable' => true, 'published_at' => now()]);
        $load->stops()->delete();
        $load->stops()->createMany([['type' => 'pickup', 'position' => 1, 'city' => 'Sarajevo', 'country_code' => 'BA', 'window_starts_at' => now()], ['type' => 'delivery', 'position' => 2, 'city' => 'Vienna', 'country_code' => 'AT', 'window_starts_at' => now()->addDay()]]);
        $shipment = $load->shipment()->firstOrFail();
        $shipment->update(['carrier' => $company->name, 'estimated_delivery_at' => now()->addDay()]);
        $shipment->events()->firstOrCreate(['title' => 'Departed Sarajevo Hub'], ['status' => 'in_transit', 'location' => 'Sarajevo, BA', 'occurred_at' => now(), 'created_by_user_id' => $users['driver_demo']->id]);

        $warehouseCompany = Company::query()
            ->where('owner_user_id', $users['warehouse_demo']->id)
            ->where('warehouse_first', true)
            ->first() ?? new Company(['slug' => 'freightbook-warehousing-co']);
        $warehouseCompany->fill([
            'owner_user_id' => $users['warehouse_demo']->id, 'name' => 'Freightbook Warehousing Co.',
            'email' => 'warehouse_demo@freightbook.test', 'country_code' => 'BA', 'city' => 'Sarajevo',
            'plan' => 'enterprise', 'status' => 'verified', 'verified_at' => now(), 'warehouse_first' => true,
        ])->save();
        $warehouseCompany->users()->syncWithoutDetaching([
            $users['warehouse_demo']->id => ['company_role' => 'admin', 'status' => 'active', 'joined_at' => now()],
        ]);
        $warehouse = Warehouse::query()->updateOrCreate(['user_id' => $users['warehouse_demo']->id, 'name' => 'Freightbook Warehousing Co. - Sarajevo Hub'], [
            'name' => 'Freightbook Warehousing Co. - Sarajevo Hub', 'address' => 'Bulevar Meše Selimovića 16', 'city' => 'Sarajevo', 'country_code' => 'BA',
            'latitude' => 43.8563, 'longitude' => 18.4131, 'total_capacity_pallets' => 500,
            'storage_types' => ['Ambient', 'Chilled', 'Bonded'], 'certifications' => ['ISO 9001', 'HACCP'],
        ]);
        WarehouseMovement::query()->where('warehouse_id', $warehouse->id)->delete();
        collect([
            ['direction' => 'inbound', 'status' => 'completed', 'scheduled_at' => now()->subDays(10), 'completed_at' => now()->subDays(10), 'customer_name' => 'Bosnalijek d.d.', 'storage_type' => 'Ambient', 'pallets' => 80, 'cbm' => 120, 'weight_kg' => 24000, 'rate' => 960, 'currency' => 'EUR', 'description' => 'Pharma stock intake'],
            ['direction' => 'inbound', 'status' => 'completed', 'scheduled_at' => now()->subDays(7), 'completed_at' => now()->subDays(7), 'customer_name' => 'Klas d.d.', 'storage_type' => 'Chilled', 'pallets' => 60, 'cbm' => 90, 'weight_kg' => 18000, 'rate' => 780, 'currency' => 'EUR', 'description' => 'Chilled goods intake'],
            ['direction' => 'inbound', 'status' => 'completed', 'scheduled_at' => now()->subDays(5), 'completed_at' => now()->subDays(5), 'customer_name' => 'Coca-Cola HBC', 'storage_type' => 'Ambient', 'pallets' => 100, 'cbm' => 150, 'weight_kg' => 30000, 'rate' => 1200, 'currency' => 'EUR', 'description' => 'Beverage stock intake'],
            ['direction' => 'outbound', 'status' => 'completed', 'scheduled_at' => now()->subDays(3), 'completed_at' => now()->subDays(3), 'customer_name' => 'Bosnalijek d.d.', 'storage_type' => 'Ambient', 'pallets' => 20, 'cbm' => 30, 'weight_kg' => 6000, 'currency' => 'EUR', 'description' => 'Partial dispatch'],
            ['direction' => 'inbound', 'status' => 'completed', 'scheduled_at' => now()->subDays(1), 'completed_at' => now()->subDays(1), 'customer_name' => 'Fabrika Duhana Sarajevo', 'storage_type' => 'Bonded', 'pallets' => 40, 'cbm' => 60, 'weight_kg' => 12000, 'rate' => 640, 'currency' => 'EUR', 'description' => 'Bonded stock intake'],
            ['direction' => 'outbound', 'status' => 'scheduled', 'scheduled_at' => now()->startOfDay()->addHours(9), 'customer_name' => 'Klas d.d.', 'storage_type' => 'Chilled', 'pallets' => 15, 'dock_number' => 'D-2', 'currency' => 'EUR', 'description' => 'Scheduled dispatch'],
            ['direction' => 'inbound', 'status' => 'scheduled', 'scheduled_at' => now()->startOfDay()->addHours(14), 'customer_name' => 'Argeta d.o.o.', 'storage_type' => 'Ambient', 'pallets' => 30, 'dock_number' => 'D-3', 'currency' => 'EUR', 'description' => 'Scheduled intake'],
            ['direction' => 'inbound', 'status' => 'scheduled', 'scheduled_at' => now()->startOfDay()->addHours(16)->addMinutes(30), 'customer_name' => 'Coca-Cola HBC', 'storage_type' => 'Ambient', 'pallets' => 25, 'dock_number' => 'D-1', 'currency' => 'EUR', 'description' => 'Scheduled intake'],
        ])->each(fn (array $movement) => WarehouseMovement::query()->create($movement + ['warehouse_id' => $warehouse->id]));

        $this->call(SubscriptionPackageSeeder::class);
        $proPackage = SubscriptionPackage::query()->where('slug', 'pro')->first();
        if ($proPackage) {
            UserSubscription::query()->updateOrCreate(['user_id' => $users['customer_demo']->id], [
                'subscription_package_id' => $proPackage->id, 'active' => true,
                'started_at' => now(), 'expires_at' => now()->addMonth(), 'remaining_tokens' => $proPackage->lena_ai_tokens,
            ]);
        }
    }
}
