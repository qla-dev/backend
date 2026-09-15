<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Draws one image for LenaAI training mode through OpenRouter's image-output models.
 *
 * Only reached after a superadmin approves an image LenaAI described (see
 * agents/lena/training/skills/generate-image.md). The model returns the picture as a base64 data URL
 * in choices.0.message.images; it comes back here as raw bytes for the chat to store.
 */
class OpenRouterImageGenerator
{
    public function __construct(private AiCallLogger $logger) {}

    /**
     * @param  list<string>  $referenceImages  data URLs of the admin's recent screenshots, used as visual reference
     * @return array{bytes: string, mime: string, extension: string, text: ?string}
     */
    public function generate(string $conversation, array $referenceImages = [], ?int $conversationId = null): array
    {
        $prompt = 'Generate exactly one image for a superadmin of the Freightbook.ai freight logistics platform. '
            .'Draw the image LenaAI described and the admin approved in the conversation below, applying their latest corrections. '
            .'Treat attached images only as visual reference. Keep any text inside the image short and correctly spelled.'
            ."\n\nConversation:\n".mb_substr($conversation, -8000);
        $payload = [
            'model' => (string) config('services.openrouter.image_model'),
            'modalities' => ['image', 'text'],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ...array_map(fn (string $url) => ['type' => 'image_url', 'image_url' => ['url' => $url]], $referenceImages),
                ],
            ]],
        ];
        $startedAt = microtime(true);

        try {
            $response = Http::withToken((string) config('services.openrouter.api_key'))
                ->acceptJson()
                ->timeout(120)
                ->withHeaders(['HTTP-Referer' => config('app.url'), 'X-Title' => 'Freightbook.ai LenaAI Training'])
                ->post((string) config('services.openrouter.url'), $payload);
        } catch (ConnectionException $exception) {
            Log::warning('Image generation failed to connect.', ['conversation_id' => $conversationId, 'error' => $exception->getMessage()]);
            $this->log($payload, null, null, $conversationId, $startedAt, false, $exception->getMessage());

            throw new RuntimeException('The image generator is not available right now. Please try again.');
        }

        $json = $response->json();
        $image = self::decode(data_get($json, 'choices.0.message.images.0.image_url.url'));
        if (! $response->successful() || ! $image) {
            $error = data_get($json, 'error.message') ?: 'The image generator did not return an image.';
            Log::warning('Image generation returned no image.', ['conversation_id' => $conversationId, 'http_status' => $response->status(), 'error' => $error]);
            $this->log($payload, is_array($json) ? $json : null, $response->status(), $conversationId, $startedAt, false, $error);

            throw new RuntimeException($error);
        }

        $this->log($payload, $json, $response->status(), $conversationId, $startedAt, true, null);
        $text = data_get($json, 'choices.0.message.content');

        return [...$image, 'text' => is_string($text) && trim($text) !== '' ? trim($text) : null];
    }

    /** @return array{bytes: string, mime: string, extension: string}|null */
    public static function decode(mixed $dataUrl): ?array
    {
        if (! is_string($dataUrl) || ! preg_match('#^data:(image/(png|jpeg|webp|gif));base64,(.+)$#s', $dataUrl, $match)) {
            return null;
        }
        $bytes = base64_decode($match[3], true);

        return $bytes === false || $bytes === ''
            ? null
            : ['bytes' => $bytes, 'mime' => $match[1], 'extension' => $match[2] === 'jpeg' ? 'jpg' : $match[2]];
    }

    private function log(array $payload, ?array $response, ?int $httpStatus, ?int $conversationId, float $startedAt, bool $success, ?string $error): void
    {
        $this->logger->record([
            'service' => 'image_generation',
            'conversation_id' => $conversationId,
            'model' => data_get($response, 'model', $payload['model']),
            'provider' => data_get($response, 'provider'),
            'generation_id' => data_get($response, 'id'),
            'finish_reason' => data_get($response, 'choices.0.finish_reason'),
            'has_attachment' => count($payload['messages'][0]['content']) > 1,
            'is_success' => $success,
            'error_message' => $error,
            // The generated picture and the reference screenshots are base64; never copy them into the log.
            'request_payload' => AiCallLogger::redactBase64($payload),
            'response_payload' => AiCallLogger::redactBase64($response),
            'prompt_tokens' => data_get($response, 'usage.prompt_tokens'),
            'completion_tokens' => data_get($response, 'usage.completion_tokens'),
            'total_tokens' => data_get($response, 'usage.total_tokens'),
            'cost_usd' => data_get($response, 'usage.cost'),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'http_status' => $httpStatus,
        ]);
    }
}
