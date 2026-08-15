<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('customer_type')->default('private');
            $table->string('status')->default('active');
            $table->string('company_name')->nullable();
            $table->string('tax_number')->nullable()->unique();
            $table->string('billing_email')->nullable();
            $table->string('billing_address')->nullable();
            $table->string('city')->nullable();
            $table->timestamps();
        });

        $customerUserIds = DB::table('users')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->where('roles.name', 'user')
            ->pluck('users.id');

        $now = now();
        foreach ($customerUserIds as $userId) {
            DB::table('customers')->insertOrIgnore([
                'user_id' => $userId,
                'customer_type' => 'private',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
