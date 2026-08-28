<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A warehouse row doubles as the warehouse *company* record in the admin console, so it carries the
// same contact/subscription columns the logistics companies table already has.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('email')->nullable()->after('name');
            $table->string('phone', 50)->nullable()->after('email');
            $table->string('tax_number', 100)->nullable()->after('phone');
            $table->string('registration_number', 100)->nullable()->after('tax_number');
            $table->string('plan', 50)->default('starter')->after('certifications');
            $table->string('status', 50)->default('pending')->after('plan');
            $table->timestamp('verified_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone', 'tax_number', 'registration_number', 'plan', 'status', 'verified_at']);
        });
    }
};
