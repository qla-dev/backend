<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LenaSpeech
{
    public ?string $generationId = null;

    public function __construct(private AiCallLogger $logger) {}

    public function synthesize(string $text, ?int $conversationId = null, string $language = 'en'): string
    {
        $key = config('services.openrouter.api_key');
        if (! $key) throw new RuntimeException('Speech provider is not configured.');

        $payload = [
            'model' => config('services.openrouter.speech_model', 'google/gemini-3.1-flash-tts-preview'),
            'voice' => 'Kore',
            'input' => $text,
            'response_format' => 'pcm',
        ];
        $started = microtime(true);
        $response = null;
        $success = false;
        $this->generationId = null;
        try {
            $response = Http::withToken($key)->connectTimeout(10)->timeout(90)
                ->post('https://openrouter.ai/api/v1/audio/speech', $payload);
            $this->generationId = $response->header('X-Generation-Id') ?: null;

            if (! $response->successful() || ! str_starts_with(strtolower($response->header('Content-Type')), 'audio/pcm') || strlen($response->body()) < 100 || strlen($response->body()) % 2 !== 0) {
                // Never expose the provider request (which contains credentials and chat text).
                throw new RuntimeException('Speech provider failed (HTTP '.$response->status().').');
            }

            $success = true;
            return self::wav($response->body());
        } finally {
            $this->logger->record([
                'service' => 'speech', 'conversation_id' => $conversationId,
                'model' => $payload['model'], 'generation_id' => $this->generationId,
                'has_attachment' => false, 'is_success' => $success,
                'http_status' => $response?->status(),
                'error_message' => $success ? null : 'Speech provider request failed.',
                'request_payload' => [...$payload, 'language' => $language],
                'response_payload' => ['audio_bytes' => $success ? strlen($response->body()) : 0, 'usage_status' => $this->generationId ? 'pending' : 'unavailable'],
                'cost_usd' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
    }

    // Gemini returns mono signed 16-bit little-endian PCM at 24 kHz.
    public static function wav(string $pcm): string
    {
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }
}
