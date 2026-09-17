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
            if (in_array($attributes['service'] ?? null, ['dispatch_chat', 'guided_answer'], true)) {
                $mode = app()->bound('request') && app('request')->input('input_mode') === 'voice' ? 'voice' : 'text';
                $attributes['request_payload'] = [...($attributes['request_payload'] ?? []), 'input_mode' => $mode];
            }
            AiCallLog::query()->create([
                'user_id' => $userId,
                ...$attributes,
            ]);

            $this->decrementSubscriptionMessage($userId, $attributes);
        } catch (Throwable $exception) {
            Log::warning('AI call log could not be recorded.', ['error' => $exception->getMessage()]);
        }
    }

    // Charge the reply once: voice replies cost two units, text replies one.
    // Scripted text answers, speech generation/replays and transcription cost no extra units -
    // transcribing the driver's recording is one half of a voice turn whose dispatch_chat reply is
    // already charged two units, so billing it again would make a spoken turn cost three.
    private function decrementSubscriptionMessage(?int $userId, array $attributes): void
    {
        // Audio is part of the same reply; replays/chunks must not charge another plan message.
        $units = self::messageUnits($attributes);
        if (! $userId || $units === 0) {
            return;
        }

        UserSubscription::query()
            ->where('user_id', $userId)
            ->where('active', true)
            ->where('remaining_tokens', '>', 0)
            ->update(['remaining_tokens' => \Illuminate\Support\Facades\DB::raw('CASE WHEN remaining_tokens >= '.$units.' THEN remaining_tokens - '.$units.' ELSE 0 END')]);
    }

    public static function messageUnits(array $attributes): int
    {
        if (($attributes['is_success'] ?? true) !== true || in_array($attributes['service'] ?? '', ['speech', 'transcription', 'skill_selection'], true)) return 0;
        if (in_array($attributes['service'] ?? '', ['dispatch_chat', 'guided_answer'], true)
            && ($attributes['request_payload']['input_mode'] ?? 'text') === 'voice') return 2;
        return ($attributes['service'] ?? '') === 'guided_answer' ? 0 : 1;
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
