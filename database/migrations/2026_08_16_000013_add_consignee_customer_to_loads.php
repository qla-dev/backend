<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->foreignId('consignee_customer_id')
                ->nullable()
                ->after('customer_user_id')
                ->constrained('customers')
                ->nullOnDelete()
                ->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::table('loads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('consignee_customer_id');
        });
    }
};
