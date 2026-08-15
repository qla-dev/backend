<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('name')->nullable()->after('user_id');
            $table->string('email')->nullable()->after('name');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('country_code', 2)->nullable()->after('phone');
            $table->timestamp('profile_authorized_at')->nullable()->after('country_code');
        });

        DB::table('drivers')
            ->whereNotNull('user_id')
            ->whereNull('profile_authorized_at')
            ->update(['profile_authorized_at' => now()]);
    }

    public function down(): void
    {
        DB::table('drivers')->whereNull('user_id')->delete();

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropColumn(['name', 'email', 'phone', 'country_code', 'profile_authorized_at']);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
