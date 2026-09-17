<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

// The ear half of Lena's voice loop - LenaSpeech is the mouth. A driver's recording comes in here
// as raw bytes and leaves as text, which DispatchChatController then handles exactly as if it had
// been typed. Lena's own reasoning is untouched by voice input, so every skill she has in the
// text chat works the same when she is spoken to.
class LenaTranscription
{
    private const MAX_ATTEMPTS = 2;

    public function __construct(private AiCallLogger $logger) {}

    /**
     * @param  string  $audio  Raw audio bytes (not base64, not a data URI).
     * @param  string  $format One of the container formats OpenRouter accepts: wav, mp3, m4a, ogg, webm, flac, aac.
     */
    public function transcribe(string $audio, string $format, ?int $conversationId = null, string $language = 'en'): string
    {
        $key = config('services.openrouter.api_key');
        if (! $key) throw new RuntimeException('Transcription provider is not configured.');

        $primaryModel = (string) config('services.openrouter.transcription_model');
        $fallbackModel = config('services.openrouter.transcription_fallback_model');
        $provider = trim((string) config('services.openrouter.transcription_provider'));
        $url = (string) config('services.openrouter.transcription_url');
        $encoded = base64_encode($audio);

        // Same shape as OpenRouterDispatchAssistant::reply(): attempt one uses the primary, and a
        // failure or an empty transcript switches to the fallback model rather than hitting the
        // same provider twice. A driver holding a mic button has no patience for a third attempt.
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $model = ($attempt > 1 && filled($fallbackModel)) ? (string) $fallbackModel : $primaryModel;
            $payload = [
                'model' => $model,
                'input_audio' => ['data' => $encoded, 'format' => $format],
                // Whisper guesses the language when it is not told, and a driver switching between
                // Bosnian and German mid-sentence makes it guess badly. Lena already knows which
                // language the interface is in, so the guess is unnecessary.
                'language' => $language,
                'temperature' => 0,
                'response_format' => 'json',
            ];
            // Only the primary is pinned to a provider; the fallback is free to land anywhere,
            // since the point of the retry is to escape whatever just failed.
            if ($attempt === 1 && $provider !== '') {
                $payload['provider'] = ['order' => [$provider], 'allow_fallbacks' => true];
            }

            $started = microtime(true);
            $response = null;
            $text = '';
            $success = false;
            try {
                $response = Http::withToken($key)->connectTimeout(10)->timeout(60)->post($url, $payload);
                if ($response->successful()) {
                    // Silence makes Whisper invent a subtitle sign-off; strip it before it becomes
                    // a message, a load field, or an answer to a questionnaire step.
                    $text = SpeechHallucinations::strip((string) $response->json('text', ''));
                    $success = $text !== '';
                }
            } catch (\Throwable $exception) {
                // Swallowed so the loop can try the fallback; the last failure is reported below.
                $lastError = $exception->getMessage();
            } finally {
                $this->logger->record([
                    'service' => 'transcription', 'conversation_id' => $conversationId,
                    'model' => $model, 'generation_id' => $response?->header('X-Generation-Id') ?: null,
                    'has_attachment' => false, 'is_success' => $success,
                    'http_status' => $response?->status(),
                    // Never log the recording itself or the provider request - one carries the
                    // driver's voice, the other the credentials.
                    'error_message' => $success ? null : 'Transcription request failed.',
                    'request_payload' => ['model' => $model, 'format' => $format, 'language' => $language, 'attempt' => $attempt, 'audio_bytes' => strlen($audio)],
                    'response_payload' => ['characters' => mb_strlen($text), 'usage' => $response?->json('usage')],
                    'cost_usd' => $response?->json('usage.cost'),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                ]);
            }

            if ($success) return $text;
            $lastError ??= 'HTTP '.($response?->status() ?? 0);
        }

        throw new RuntimeException('Transcription failed ('.$lastError.').');
    }
}
