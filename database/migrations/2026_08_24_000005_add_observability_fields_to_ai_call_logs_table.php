<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Adds the fields comparable LLM-observability tools (OpenRouter's own activity log, Langfuse,
// Helicone) track alongside cost/tokens: which upstream provider actually served the request,
// OpenRouter's own generation id (for cross-referencing in their dashboard), why the model
// stopped generating, the sampling temperature used, the HTTP status code, the prompt/completion
// token breakdown OpenRouter reports under usage.*_tokens_details, and how many attempts the
// retrying scanners needed before success or final failure.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_call_logs', function (Blueprint $table) {
            $table->string('provider')->nullable()->after('model');
            $table->string('generation_id')->nullable()->after('provider');
            $table->string('finish_reason', 40)->nullable()->after('generation_id');
            $table->decimal('temperature', 3, 2)->nullable()->after('finish_reason');
            $table->unsignedSmallInteger('http_status')->nullable()->after('duration_ms');
            $table->unsignedInteger('cached_tokens')->nullable()->after('total_tokens');
            $table->unsignedInteger('reasoning_tokens')->nullable()->after('cached_tokens');
            $table->unsignedTinyInteger('attempt_count')->nullable()->after('reasoning_tokens');

            $table->index('generation_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_call_logs', function (Blueprint $table) {
            $table->dropIndex(['generation_id']);
            $table->dropColumn(['provider', 'generation_id', 'finish_reason', 'temperature', 'http_status', 'cached_tokens', 'reasoning_tokens', 'attempt_count']);
        });
    }
};
