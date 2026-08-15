<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->string('name')->nullable()->after('user_id');
            $table->string('email')->nullable()->after('name');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('country_code', 2)->nullable()->after('phone');
            $table->string('source', 50)->nullable()->after('country_code');
            $table->unsignedBigInteger('source_id')->nullable()->after('source');
            $table->timestamp('profile_authorized_at')->nullable()->after('source_id');
            $table->unique(['source', 'source_id']);
        });

        DB::table('customers')
            ->whereNotNull('user_id')
            ->whereNull('profile_authorized_at')
            ->update(['profile_authorized_at' => now()]);
    }

    public function down(): void
    {
        DB::table('customers')->whereNull('user_id')->delete();

        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['source', 'source_id']);
            $table->dropColumn([
                'name',
                'email',
                'phone',
                'country_code',
                'source',
                'source_id',
                'profile_authorized_at',
            ]);
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};
