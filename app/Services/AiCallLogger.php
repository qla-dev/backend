<?php

namespace App\Services;

use App\Models\AiCallLog;
use App\Models\UserSubscription;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

// Persists one row per outbound OpenRouter call for the AI Stats screen. Called from all three
// OpenRouter-facing services (OpenRouterDispatchAssistant, OpenRouterLoadScanner,
// OpenRouterBulkLoadScanner) right after the HTTP response comes back, on both success and
// failure - logging must never be the reason a real AI feature fails, so record() swallows its
// own errors instead of throwing.
class AiCallLogger
{
    public function record(array $attributes): void
    {
        try {
            $userId = Auth::id();
            AiCallLog::query()->create([
                'user_id' => $userId,
                ...$attributes,
            ]);

            $this->decrementSubscriptionMessage($userId, $attributes);
        } catch (Throwable $exception) {
            Log::warning('AI call log could not be recorded.', ['error' => $exception->getMessage()]);
        }
    }

    // Each successful, non-free AI call consumes one message from the caller's plan allowance.
    // guided_answer is the one free/scripted service (see LENA_ALPHA_DISPLAY_TOKENS in
    // AiStatsView.tsx, which shows a fixed placeholder for it rather than its real, always-0 token
    // count) and never decrements the balance. A no-op for users without an active subscription
    // row - e.g. superadmin/master, who have unlimited access instead of a plan.
    private function decrementSubscriptionMessage(?int $userId, array $attributes): void
    {
        if (! $userId || ($attributes['is_success'] ?? true) !== true || ($attributes['service'] ?? null) === 'guided_answer') {
            return;
        }

        UserSubscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('remaining_tokens', '>', 0)
            ->decrement('remaining_tokens');
    }

    // Strips base64 file/image data out of a request payload before it's stored, so a scanned
    // PDF or photo doesn't get duplicated into the log table - everything else in the payload
    // (system/user prompt text, schema, model, etc.) is kept exactly as sent.
    public static function redactBase64(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($item) => self::redactBase64($item), $value);
        }

        if (is_string($value) && preg_match('/^data:[^;]+;base64,(.+)/s', $value, $match) === 1) {
            $bytes = (int) (strlen($match[1]) * 3 / 4);

            return '[omitted, ~'.$bytes.' bytes]';
        }

        return $value;
    }
}
