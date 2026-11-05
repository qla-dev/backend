<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('company_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('company_role_id')->nullable();
            $table->json('business_departments')->nullable();
            $table->json('subdepartments')->nullable();
        });
        // Snapshot each existing membership; future team changes never change the account role.
        DB::table('company_user')->orderBy('id')->chunkById(200, function ($members): void {
            foreach ($members as $member) {
                $role = DB::table('users')->join('roles', 'roles.id', '=', 'users.role_id')->where('users.id', $member->user_id)
                    ->whereIn('roles.name', \App\Services\CompanyTeamAccess::ROLES)->value('roles.id');
                $organization = app(\App\Services\CompanyTeamAccess::class)->organization($member);
                DB::table('company_user')->where('id', $member->id)->update(['company_role_id' => $role,
                    'business_departments' => json_encode($organization['business_departments']), 'subdepartments' => json_encode($organization['subdepartments'])]);
            }
        });
    }

    public function down(): void
    {
        // Role assignments are business data; rollback code without deleting them.
        throw new RuntimeException('Company role data must be retained. Roll back application code instead.');
    }
};
