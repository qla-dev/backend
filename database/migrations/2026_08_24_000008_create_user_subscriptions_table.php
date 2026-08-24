<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('subscription_package_id')->constrained('subscription_packages')->restrictOnDelete()->cascadeOnUpdate();
            $table->boolean('active')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('remaining_tokens')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};
