<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ops_checklist_versions', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('version'); $t->json('items'); $t->unsignedBigInteger('created_by'); $t->timestamps();
            $t->unique(['company_id', 'version']);
        });
        Schema::create('ops_operational_state', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->unique()->constrained('ops_orders')->restrictOnDelete();
            $t->unsignedInteger('revision')->default(0); $t->unsignedBigInteger('template_id')->nullable();
            $t->json('items')->nullable(); $t->json('context')->nullable(); $t->timestamps();
        });
        Schema::create('ops_inspection_cases', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('order_id')->constrained('ops_orders')->restrictOnDelete();
            $t->string('control_type', 20); $t->string('local_key', 80);
            $t->string('status', 30)->default('draft'); $t->string('external_reference', 120)->nullable();
            $t->string('authority', 255)->nullable(); $t->unsignedBigInteger('responsible_user_id')->nullable();
            $t->text('next_action')->nullable(); $t->timestamp('due_at')->nullable();
            $t->json('document_ids'); $t->json('document_hashes')->nullable(); $t->json('item_outcomes')->nullable();
            $t->text('note')->nullable(); $t->unsignedInteger('revision')->default(1);
            $t->unsignedBigInteger('recorded_by'); $t->timestamps();
            $t->unique(['company_id', 'order_id', 'local_key']);
            $t->index(['company_id', 'external_reference']);
        });
        Schema::create('company_country_profiles', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->unique()->constrained()->restrictOnDelete();
            $t->char('country_code', 2); $t->string('language', 10); $t->char('currency', 3);
            $t->string('timezone', 60); $t->unsignedInteger('revision')->default(1); $t->timestamps();
        });
        Schema::create('company_capability_verifications', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->unsignedBigInteger('subject_user_id')->nullable();
            $t->char('country_code', 2); $t->string('capability', 40); $t->string('activity', 40);
            $t->string('status', 30)->default('draft'); $t->unsignedInteger('revision')->default(1);
            $t->string('rule_version', 30); $t->json('document_ids'); $t->text('registry_reference')->nullable();
            $t->date('expires_at')->nullable(); $t->unsignedBigInteger('submitted_by');
            $t->unsignedBigInteger('reviewed_by')->nullable(); $t->timestamp('reviewed_at')->nullable();
            $t->string('verification_method', 30)->nullable(); $t->text('reason')->nullable(); $t->timestamps();
        });
        Schema::create('compliance_document_links', function (Blueprint $t) {
            $t->id(); $t->foreignId('company_id')->constrained()->restrictOnDelete();
            $t->foreignId('document_id')->unique()->constrained('documents')->restrictOnDelete();
            $t->string('sha256', 64); $t->unsignedBigInteger('registered_by'); $t->timestamps();
        });
        Schema::create('compliance_audit', function (Blueprint $t) {
            $t->id(); $t->unsignedBigInteger('company_id')->index(); $t->string('entity', 40);
            $t->unsignedBigInteger('entity_id'); $t->unsignedBigInteger('actor_id');
            $t->string('action', 40); $t->json('before')->nullable(); $t->json('after')->nullable(); $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Explicitly no automatic rollback: inspection decisions and verification evidence are retained.
        throw new RuntimeException('Compliance records require a reviewed retention/export procedure; automatic rollback is disabled.');
    }
};
