<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->boolean('warehouse_first')->default(false)->index()->after('description');
        });

        $companyRoleId = DB::table('roles')->where('name', 'company')->value('id');
        $warehouseRoleId = DB::table('roles')->where('name', 'warehouse')->value('id');

        DB::table('warehouses')->orderBy('id')->get()->groupBy('user_id')->each(function ($facilities, $userId) use ($companyRoleId, $warehouseRoleId): void {
            $first = $facilities->first();
            $companyId = DB::table('company_user')->where('user_id', $userId)->where('status', 'active')->value('company_id')
                ?? DB::table('companies')->where('owner_user_id', $userId)->value('id');

            if (! $companyId) {
                $baseSlug = Str::slug($first->name ?: 'warehouse-company') ?: 'warehouse-company';
                $slug = $baseSlug;
                $suffix = 2;
                while (DB::table('companies')->where('slug', $slug)->exists()) {
                    $slug = $baseSlug.'-'.$suffix++;
                }
                $now = now();
                $companyId = DB::table('companies')->insertGetId([
                    'owner_user_id' => $userId,
                    'name' => $first->name ?: 'Warehouse company',
                    'slug' => $slug,
                    'email' => $first->email ?? null,
                    'phone' => $first->phone ?? null,
                    'tax_number' => $first->tax_number ?? null,
                    'registration_number' => $first->registration_number ?? null,
                    'country_code' => strtoupper($first->country_code ?: 'BA'),
                    'city' => $first->city ?? null,
                    'address' => $first->address ?? null,
                    'plan' => $first->plan ?? 'starter',
                    'status' => $first->status ?? 'pending',
                    'verified_at' => $first->verified_at ?? null,
                    'warehouse_first' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('companies')->where('id', $companyId)->update(['warehouse_first' => true, 'updated_at' => now()]);
            }

            DB::table('company_user')->insertOrIgnore([
                'company_id' => $companyId,
                'user_id' => $userId,
                'company_role' => 'admin',
                'status' => 'active',
                'joined_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if ($companyRoleId && $warehouseRoleId) {
                DB::table('users')->where('id', $userId)->where('role_id', $warehouseRoleId)->update(['role_id' => $companyRoleId]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table): void {
            $table->dropColumn('warehouse_first');
        });
    }
};
