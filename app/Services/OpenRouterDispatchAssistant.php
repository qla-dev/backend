<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenRouterDispatchAssistant
{
    public function __construct(private readonly AiCallLogger $logger) {}

    public function reply(string $systemPrompt, array $history, ?int $conversationId = null, bool $hasAttachment = false): string
    {
        $payload = [
            'model' => config('services.openrouter.model'),
            'temperature' => 0.5,
            'usage' => ['include' => true],
            // Gemini 2.5 Flash (the configured model) ships with "thinking" on by default, and
            // OpenRouter has a well-documented failure mode where that internal reasoning consumes
            // the response and the final answer comes back as a null message.content even though
            // finish_reason reports a normal "stop" - not an HTTP error, so the generic retry below
            // wouldn't even see it as a failure without the empty-content check. Reasoning adds cost
            // and latency this dispatcher chat never needed anyway, so it's disabled outright rather
            // than just worked around.
            'reasoning' => ['enabled' => false],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...$history,
            ],
        ];

        // Belt-and-suspenders: even with reasoning disabled, the provider occasionally still
        // returns a "stop" completion with null content for reasons that never surface in the
        // response body. That's not deterministic, so one immediate retry resolves it far more
        // often than surfacing an error to the user for what's usually a one-off blip.
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $startedAt = microtime(true);

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
                    $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, false, data_get($response->json(), 'error.message'));

                    throw new RuntimeException(data_get($response->json(), 'error.message', 'AI dispatcher is not available right now.'));
                }

                $content = data_get($response->json(), 'choices.0.message.content');
                if (is_string($content) && trim($content) !== '') {
                    $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, true, null);

                    return trim($content);
                }

                $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, false, 'The AI dispatcher did not return a reply.'.($attempt === 1 ? ' Retrying once.' : ''));

                if ($attempt === 1) {
                    continue;
                }

                throw new RuntimeException('The AI dispatcher did not return a reply.');
            } catch (ConnectionException $exception) {
                Log::warning('AI dispatcher reply failed.', ['error' => $exception->getMessage()]);
                $this->log($payload, null, null, $conversationId, $hasAttachment, $startedAt, false, $exception->getMessage());

                if ($attempt === 1) {
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
