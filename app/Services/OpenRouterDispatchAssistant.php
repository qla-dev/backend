<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class OpenRouterDispatchAssistant
{
    public function reply(string $systemPrompt, array $history): string
    {
        try {
            $response = Http::withToken((string) config('services.openrouter.api_key'))
                ->acceptJson()
                ->timeout(60)
                ->withHeaders([
                    'HTTP-Referer' => config('app.url'),
                    'X-Title' => 'Freightbook.ai AI Dispatcher',
                ])
                ->post((string) config('services.openrouter.url'), [
                    'model' => config('services.openrouter.model'),
                    'temperature' => 0.5,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ...$history,
                    ],
                ]);

            if (! $response->successful()) {
                throw new RuntimeException(data_get($response->json(), 'error.message', 'AI dispatcher is not available right now.'));
            }

            $content = data_get($response->json(), 'choices.0.message.content');
            if (is_string($content) && trim($content) !== '') {
                return trim($content);
            }

            throw new RuntimeException('The AI dispatcher did not return a reply.');
        } catch (ConnectionException $exception) {
            Log::warning('AI dispatcher reply failed.', ['error' => $exception->getMessage()]);

            throw new RuntimeException('AI dispatcher is not available right now. Please try again.');
        }
    }
}
