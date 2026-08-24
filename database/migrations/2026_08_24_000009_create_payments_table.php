<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('subscription_package_id')->nullable()->constrained('subscription_packages')->nullOnDelete()->cascadeOnUpdate();
            $table->string('type'); // 'topup' or 'package'
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3)->default('BAM');
            $table->unsignedInteger('tokens'); // LenaAI messages credited by this payment
            $table->string('status')->default('completed'); // no external gateway yet, so always completed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
