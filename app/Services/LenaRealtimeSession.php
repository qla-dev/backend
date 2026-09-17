<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Mints the short-lived client secret the app needs to open a WebRTC call with OpenAI's realtime
 * model, and defines what that model is allowed to do once the call is up.
 *
 * The realtime model is deliberately only a voice layer: ears, mouth, and the turn-taking that lets
 * a driver interrupt mid-sentence. It is told almost nothing about freight. Every question needing
 * knowledge, data or an action is delegated back to the Lena that already exists -
 * DispatchChatController and its modes - through the single ask_lena tool below.
 *
 * That is what makes a live call cover all of Lena's skills instead of a hand-picked subset: the
 * load questionnaire, tracking, booking, HS codes and the legal jurisdictions are not reimplemented
 * here and cannot drift from the text chat, because they *are* the text chat. The trade is latency:
 * a delegated turn still costs a full dispatch-chat round trip, so the model is told to say it is
 * checking before it calls the tool rather than leaving the driver in silence.
 *
 * The API key is read from config at call time and never reaches the device. With no key set,
 * mint() throws and the call screen reports the feature as unconfigured; nothing else is affected.
 */
class LenaRealtimeSession
{
    private const LANGUAGE_NAMES = [
        'en' => 'English',
        'de' => 'German',
        'bs' => 'Bosnian',
        'hr' => 'Croatian',
        'sr' => 'Serbian',
    ];

    public function __construct(private AiCallLogger $logger) {}

    public function isConfigured(): bool
    {
        return filled(config('services.openai.api_key'));
    }

    /**
     * @return array{client_secret: string, expires_at: int|null, model: string, calls_url: string}
     */
    public function mint(string $language, ?int $conversationId = null, ?int $userId = null): array
    {
        $key = config('services.openai.api_key');
        if (! $key) throw new RuntimeException('The live call is not configured.');

        $model = (string) config('services.openai.realtime_model');
        $voice = (string) config('services.openai.realtime_voice');
        $payload = [
            'session' => [
                'type' => 'realtime',
                'model' => $model,
                'instructions' => $this->instructions($language),
                'audio' => [
                    // Transcribing the caller's own speech is what lets the call show a readable
                    // transcript beside it, and what gets the call saved as a normal conversation
                    // afterwards. Without it the caller's turns exist only as audio nobody keeps.
                    'input' => ['transcription' => ['model' => 'whisper-1', 'language' => $language]],
                    'output' => ['voice' => $voice],
                ],
                'tools' => self::tools(),
                'tool_choice' => 'auto',
            ],
        ];

        $started = microtime(true);
        $response = null;
        $success = false;
        try {
            $request = Http::withToken($key)->connectTimeout(10)->timeout(30);
            // Lets OpenAI's abuse tooling tell our users apart without ever learning who they are.
            if ($userId) {
                $request = $request->withHeaders(['OpenAI-Safety-Identifier' => hash('sha256', 'freightbook:'.$userId)]);
            }
            $response = $request->post((string) config('services.openai.realtime_client_secrets_url'), $payload);

            // The endpoint has returned the secret both at the top level and nested under
            // client_secret across revisions; accept either rather than break on an API tidy-up.
            $secret = (string) ($response->json('value') ?? $response->json('client_secret.value') ?? '');
            if (! $response->successful() || $secret === '') {
                throw new RuntimeException('Realtime session could not be created (HTTP '.$response->status().').');
            }
            $success = true;

            return [
                'client_secret' => $secret,
                'expires_at' => $response->json('expires_at') ?? $response->json('client_secret.expires_at'),
                'model' => $model,
                'calls_url' => (string) config('services.openai.realtime_calls_url'),
            ];
        } finally {
            $this->logger->record([
                'service' => 'realtime_session', 'conversation_id' => $conversationId,
                'model' => $model, 'generation_id' => null,
                'has_attachment' => false, 'is_success' => $success,
                'http_status' => $response?->status(),
                // Never surface the provider request: it carries the credentials.
                'error_message' => $success ? null : 'Realtime session request failed.',
                // Instructions and tool schema are static and version-controlled right here, so
                // logging them on every call would bloat the table for nothing.
                'request_payload' => ['model' => $model, 'language' => $language, 'voice' => $voice],
                'response_payload' => ['minted' => $success],
                'cost_usd' => null,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
    }

    /**
     * The one tool the call has. The app executes it against the same endpoints the text chat uses,
     * under the driver's own token, so a call can never reach data the driver could not already
     * see. Its result is whatever the real Lena replied, stripped of the [[MARKER]] syntax the text
     * chat uses for buttons - a marker read out loud is nonsense.
     */
    public static function tools(): array
    {
        return [[
            'type' => 'function',
            'name' => 'ask_lena',
            'description' => 'Ask Lena, the Freightbook.ai freight assistant, anything the driver or dispatcher '
                .'needs. Use this for every request involving real work or real data: posting or creating a load, '
                .'storing goods in a warehouse, tracking a shipment, booking or taking a load, HS codes and tariff '
                .'classification, customs, VAT, import duties and transport law, and any question about this '
                .'account\'s own loads, shipments or documents. Lena reads the live database and runs the load '
                .'questionnaire herself, so never guess an answer you could get from her. Answer directly without '
                .'this tool only for greetings, small talk, and repeating or clarifying something already said in '
                .'this call.',
            'parameters' => [
                'type' => 'object',
                'properties' => [
                    'question' => [
                        'type' => 'string',
                        'description' => 'What the caller asked, written out in full in the language they spoke. '
                            .'Resolve pronouns and references from earlier in the call into explicit terms, because '
                            .'Lena does not hear the call - she receives only this text. Include any number the '
                            .'caller said, such as a tracking or booking reference, exactly as spoken.',
                    ],
                ],
                'required' => ['question'],
                'additionalProperties' => false,
            ],
        ]];
    }

    private function instructions(string $language): string
    {
        $spoken = self::LANGUAGE_NAMES[$language] ?? 'English';

        return implode("\n\n", [
            'You are Lena, the voice of Freightbook.ai, on a phone call with a driver or dispatcher.',

            "Speak {$spoken}, and keep speaking {$spoken} unless the caller clearly switches language.",

            'You are the caller\'s voice into Lena, not Lena\'s knowledge. You do not know this account\'s loads, '
                .'shipments, prices, documents, HS codes or the law. The ask_lena tool does. Call it for every '
                .'request that involves real work or real information, and answer from what it returns rather than '
                .'from your own guess. Never invent a shipment status, a price, a location, a tariff number, a legal '
                .'rule or a reference number.',

            "Before calling the tool, say one short line so the caller is not left in silence - \"let me check that\" "
                ."or its equivalent in {$spoken}. The tool can take a few seconds. Do not narrate the wait further, "
                .'and do not repeat yourself while it runs.',

            'This is speech, not writing. Keep replies to a couple of sentences. Never read out symbols, asterisks, '
                .'bullet markers or anything in double square brackets - say the words instead. Read numbers, '
                .'references and dates the way a person says them aloud. When the tool returns a long answer, give '
                .'the caller the part that matters and offer the rest.',

            'When the tool returns a question - Lena\'s load questionnaire asks one thing at a time - put that '
                .'question to the caller in your own spoken words and send their answer straight back through '
                .'ask_lena. Work through the questionnaire one step at a time, at the caller\'s pace.',

            'The caller is often driving. Be brief, be calm, and let them interrupt you. If they cut you off, stop '
                .'talking immediately and listen. If you did not hear something clearly, ask them to repeat it '
                .'rather than guessing - especially a number.',
        ]);
    }
}
