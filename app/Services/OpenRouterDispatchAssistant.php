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
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...$history,
            ],
        ];
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

            $this->log($payload, $response->json(), $response->status(), $conversationId, $hasAttachment, $startedAt, false, 'The AI dispatcher did not return a reply.');

            throw new RuntimeException('The AI dispatcher did not return a reply.');
        } catch (ConnectionException $exception) {
            Log::warning('AI dispatcher reply failed.', ['error' => $exception->getMessage()]);
            $this->log($payload, null, null, $conversationId, $hasAttachment, $startedAt, false, $exception->getMessage());

            throw new RuntimeException('AI dispatcher is not available right now. Please try again.');
        }
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
