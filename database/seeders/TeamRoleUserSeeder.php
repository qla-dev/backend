<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class TeamRoleUserSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::query()->where('slug', 'freightbook-logistics-hub')->first() ?? Company::query()->first();
        if (! $company) {
            return;
        }

        foreach ([
            'manager' => ['Manager', 'manager_demo'],
            'dispatcher' => ['Dispatcher', 'dispatcher_demo'],
            'customs_officer' => ['Customs Officer', 'customs_officer_demo'],
        ] as $roleName => [$label, $username]) {
            $role = Role::query()->updateOrCreate(
                ['name' => $roleName],
                ['label' => $label, 'permissions' => [], 'is_active' => true]
            );
            $user = User::query()->updateOrCreate(['username' => $username], [
                'role_id' => $role->id,
                'name' => "Demo {$label}",
                'email' => "{$username}@freightbook.test",
                'password' => 'demo12345',
                'language' => 'bs',
                'country_code' => 'BA',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $company->users()->syncWithoutDetaching([
                $user->id => ['status' => 'active', 'joined_at' => now()],
            ]);
        }

        $warehouseCompany = Company::query()->where('slug', 'freightbook-warehousing-co')->first();
        if ($warehouseCompany) {
            $managerRole = Role::query()->where('name', 'manager')->firstOrFail();
            $warehouseManager = User::query()->updateOrCreate(['username' => 'warehouse_manager_demo'], [
                'role_id' => $managerRole->id,
                'name' => 'Demo Warehouse Manager',
                'email' => 'warehouse_manager_demo@freightbook.test',
                'password' => 'demo12345',
                'language' => 'bs',
                'country_code' => 'BA',
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $warehouseCompany->users()->syncWithoutDetaching([
                $warehouseManager->id => ['status' => 'active', 'joined_at' => now()],
            ]);
        }
    }
}
