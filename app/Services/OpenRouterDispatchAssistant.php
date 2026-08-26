<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenRouterDispatchAssistant
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(private readonly AiCallLogger $logger) {}

    public function reply(string $systemPrompt, array $history, ?int $conversationId = null, bool $hasAttachment = false): string
    {
        $primaryModel = (string) config('services.openrouter.model');
        $fallbackModel = config('services.openrouter.fallback_model');

        // Belt-and-suspenders: even with reasoning disabled, the provider occasionally still
        // returns a "stop" completion with null content for reasons that never surface in the
        // response body. That's not deterministic, so one immediate retry resolves it far more
        // often than surfacing an error to the user for what's usually a one-off blip. When a
        // fallback model is configured, the retry switches to it instead of hitting the same
        // provider again - a transient Gemini-specific glitch has no reason to also affect a
        // completely different model, so this gives the retry a real chance of a different outcome
        // rather than just rolling the dice on the same flaky call again.
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $model = ($attempt > 1 && filled($fallbackModel)) ? $fallbackModel : $primaryModel;
            $payload = [
                'model' => $model,
                'temperature' => 0.5,
                'usage' => ['include' => true],
                // Gemini 2.5 Flash (the default primary model) ships with "thinking" on by default,
                // and OpenRouter has a well-documented failure mode where that internal reasoning
                // consumes the response and the final answer comes back as a null message.content
                // even though finish_reason reports a normal "stop" - not an HTTP error, so the
                // empty-content check below is what actually catches it. Reasoning adds cost and
                // latency this dispatcher chat never needed anyway, so it's disabled outright for
                // every model, not just the one known to need it.
                'reasoning' => ['enabled' => false],
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ...$history,
                ],
            ];
            $startedAt = microtime(true);
            $logContext = ['attempt' => $attempt, 'max_attempts' => self::MAX_ATTEMPTS, 'conversation_id' => $conversationId, 'model' => $model];

            try {
                $response = Http::withToken((string) config('services.openrouter.api_key'))
                    ->acceptJson()
                    ->timeout(60)
                    ->withHeaders([
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'Freightbook.ai AI Dispatcher',
                    ])
                    ->post((string) config('services.openrouter.url'), $payload);

                if (! $response->successful()) {
                    $errorMessage = data_get($response->json(), 'error.message');
                    Log::warning('AI dispatcher call returned a non-successful response.', [
                        ...$logContext,
                        'http_status' => $response->status(),
                        'error' => $errorMessage,
                        'body' => $response->body(),
                    ]);
                    $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, false, $errorMessage);

                    throw new RuntimeException($errorMessage ?: 'AI dispatcher is not available right now.');
                }

                $content = data_get($response->json(), 'choices.0.message.content');
                if (is_string($content) && trim($content) !== '') {
                    $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, true, null);

                    return trim($content);
                }

                Log::warning('AI dispatcher call returned no content.', [
                    ...$logContext,
                    'http_status' => $response->status(),
                    'finish_reason' => data_get($response->json(), 'choices.0.finish_reason'),
                    'generation_id' => data_get($response->json(), 'id'),
                ]);
                $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, false, 'The AI dispatcher did not return a reply.'.($attempt < self::MAX_ATTEMPTS ? ' Retrying automatically.' : ''));

                if ($attempt < self::MAX_ATTEMPTS) {
                    continue;
                }

                throw new RuntimeException('The AI dispatcher did not return a reply.');
            } catch (ConnectionException $exception) {
                Log::warning('AI dispatcher call failed to connect.', [...$logContext, 'error' => $exception->getMessage()]);
                $this->log($payload, null, null, $conversationId, $hasAttachment, $startedAt, false, $exception->getMessage());

                if ($attempt < self::MAX_ATTEMPTS) {
                    continue;
                }

                throw new RuntimeException('AI dispatcher is not available right now. Please try again.');
            }
        }

        throw new RuntimeException('AI dispatcher is not available right now. Please try again.');
    }

    private function log(array $payload, ?array $response, ?int $httpStatus, ?int $conversationId, bool $hasAttachment, float $startedAt, bool $success, ?string $error): void
    {
        $this->logger->record([
            'service' => 'dispatch_chat',
            'conversation_id' => $conversationId,
            'model' => data_get($response, 'model', $payload['model']),
            'provider' => data_get($response, 'provider'),
            'generation_id' => data_get($response, 'id'),
            'finish_reason' => data_get($response, 'choices.0.finish_reason'),
            'temperature' => $payload['temperature'] ?? null,
            'has_attachment' => $hasAttachment,
            'is_success' => $success,
            'error_message' => $error,
            'request_payload' => AiCallLogger::redactBase64($payload),
            'response_payload' => $response,
            'prompt_tokens' => data_get($response, 'usage.prompt_tokens'),
            'completion_tokens' => data_get($response, 'usage.completion_tokens'),
            'total_tokens' => data_get($response, 'usage.total_tokens'),
            'cached_tokens' => data_get($response, 'usage.prompt_tokens_details.cached_tokens'),
            'reasoning_tokens' => data_get($response, 'usage.completion_tokens_details.reasoning_tokens'),
            'cost_usd' => data_get($response, 'usage.cost'),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'http_status' => $httpStatus,
        ]);
    }
}
