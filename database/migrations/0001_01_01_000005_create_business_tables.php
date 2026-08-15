<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('load_id')->nullable()->constrained('loads')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('channel')->default('inapp');
            $table->string('subject')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
        });

        Schema::create('conversation_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();
            $table->unique(['conversation_id', 'user_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('sender_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->timestamp('sent_at')->index();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('load_id')->nullable()->constrained('loads')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('issued_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('number')->unique();
            $table->string('status')->default('draft');
            $table->char('currency', 3)->default('EUR');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->date('issued_at');
            $table->date('due_at');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('load_id')->nullable()->constrained('loads')->nullOnDelete()->cascadeOnUpdate();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total', 14, 2);
            $table->timestamps();
        });

        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->string('subject');
            $table->longText('html_body');
            $table->json('design')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('email_campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained('email_templates')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->string('name');
            $table->string('status')->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('company_id')->nullable()->constrained('companies')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('email');
            $table->string('status')->default('pending');
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
            $table->unique(['email_campaign_id', 'email']);
        });

        Schema::create('company_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('invited_by_user_id')->constrained('users')->restrictOnDelete()->cascadeOnUpdate();
            $table->foreignId('accepted_by_user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('email');
            $table->string('token', 64)->unique();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_invitations');
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('email_templates');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_user');
        Schema::dropIfExists('conversations');
    }
};
