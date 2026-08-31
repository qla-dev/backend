<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ([
            'manager' => 'Manager',
            'dispatcher' => 'Dispatcher',
            'customs_officer' => 'Customs Officer',
        ] as $name => $label) {
            DB::table('roles')->updateOrInsert(
                ['name' => $name],
                ['label' => $label, 'permissions' => json_encode([]), 'is_active' => true, 'updated_at' => $now, 'created_at' => $now]
            );
        }

        if (Schema::hasColumn('company_user', 'company_role')) {
            Schema::table('company_user', function (Blueprint $table): void {
                $table->dropColumn('company_role');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('company_user', 'company_role')) {
            Schema::table('company_user', function (Blueprint $table): void {
                $table->string('company_role')->default('member')->after('invited_by_user_id');
            });
        }
    }
};
