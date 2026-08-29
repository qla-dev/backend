<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('headline')->nullable()->after('avatar_url');
            $table->text('bio')->nullable()->after('headline');
        });

        Schema::table('companies', function (Blueprint $table): void {
            $table->string('website')->nullable()->after('address');
            $table->string('logo_url')->nullable()->after('website');
            $table->text('description')->nullable()->after('logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['headline', 'bio']));
        Schema::table('companies', fn (Blueprint $table) => $table->dropColumn(['website', 'logo_url', 'description']));
    }
};
