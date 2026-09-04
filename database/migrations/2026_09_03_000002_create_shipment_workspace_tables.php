<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_workspace', function (Blueprint $table): void {
            $table->id();
            $table->string('reference', 40)->unique();
            $table->foreignId('load_id')->unique()->constrained('loads')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('shipment_id')->nullable()->unique()->constrained('shipments')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('accepted_offer_id')->unique()->constrained('offers')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('provider_company_id')->nullable()->constrained('companies')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('provider_user_id')->nullable()->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('conversation_id')->nullable()->unique()->constrained('conversations')->nullOnDelete()->cascadeOnUpdate();
            $table->string('status', 30)->default('booked')->index();
            $table->char('currency', 3);
            $table->decimal('agreed_amount', 14, 2);
            $table->json('load_snapshot');
            $table->json('offer_snapshot');
            $table->json('parties_snapshot');
            $table->json('operational_checklist');
            $table->timestamp('booked_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_workspace');
    }
};
