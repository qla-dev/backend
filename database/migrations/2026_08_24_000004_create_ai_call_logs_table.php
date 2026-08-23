<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per outbound OpenRouter call (see App\Services\AiCallLogger), across all three call
// sites: OpenRouterDispatchAssistant (LenaAI chat replies), OpenRouterLoadScanner (single-load
// document/text scan), OpenRouterBulkLoadScanner (bulk import scan). Logged on both success and
// failure so nothing is hidden from the AI Stats screen, including $0-cost or short/simple
// replies. request_payload has any base64 file data redacted before storage (see
// AiCallLogger::redactBase64) - response_payload is stored as-is, including the raw `usage` block
// OpenRouter returns when the request includes `usage.include = true`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_call_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service', 40);
            $table->foreignId('conversation_id')->nullable()->constrained('conversations')->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete()->cascadeOnUpdate();
            $table->string('model')->nullable();
            $table->boolean('has_attachment')->default(false);
            $table->boolean('is_success')->default(true);
            $table->text('error_message')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->unsignedInteger('total_tokens')->nullable();
            $table->decimal('cost_usd', 10, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index('service');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_call_logs');
    }
};
