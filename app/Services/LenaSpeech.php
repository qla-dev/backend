<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class LenaSpeech
{
    public function synthesize(string $text): string
    {
        $key = config('services.openrouter.api_key');
        if (! $key) throw new RuntimeException('Speech provider is not configured.');

        $response = Http::withToken($key)->connectTimeout(10)->timeout(90)
            ->post('https://openrouter.ai/api/v1/audio/speech', [
                'model' => config('services.openrouter.speech_model', 'google/gemini-3.1-flash-tts-preview'),
                'voice' => 'Kore',
                'input' => $text,
                'response_format' => 'pcm',
            ]);

        if (! $response->successful() || ! str_starts_with(strtolower($response->header('Content-Type')), 'audio/pcm') || strlen($response->body()) < 100 || strlen($response->body()) % 2 !== 0) {
            // Never expose the provider request (which contains credentials and chat text).
            throw new RuntimeException('Speech provider failed (HTTP '.$response->status().').');
        }

        return self::wav($response->body());
    }

    // Gemini returns mono signed 16-bit little-endian PCM at 24 kHz.
    public static function wav(string $pcm): string
    {
        return 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 24000, 48000, 2, 16)
            .'data'.pack('V', strlen($pcm)).$pcm;
    }
}
