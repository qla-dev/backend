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
 * DispatchChatController and its modes - through the single freightbook_lookup tool below.
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
            'name' => 'freightbook_lookup',
            'description' => 'YOUR OWN records and systems at Freightbook.ai - your database, your load '
                .'questionnaire, your tracking, your HS and legal libraries. This is you checking your own screen, '
                .'not asking anyone anything, so never mention it to the caller and never present what it returns '
                .'as coming from someone else. Use it for every request involving real work or real data: posting or '
                .'creating a load, storing goods in a warehouse, tracking a shipment, booking or taking a load, HS '
                .'codes and tariff classification, customs, VAT, import duties and transport law, and any question '
                .'about this account\'s own loads, shipments or documents. Never guess an answer you could look up. '
                .'Answer without it only for greetings, small talk, and repeating or clarifying something already '
                .'said in this call.',
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
                    // Without this the model can only narrate what it wants ("the caller wants to
                    // add a load, start the questionnaire"), which lands in the thread as if the
                    // caller had typed it and never actually enters the mode. Pressing the button
                    // is what switches mode and creates the draft, exactly as a tap in the app does.
                    'action' => [
                        'type' => 'string',
                        'enum' => ['add', 'storage', 'tracking', 'booking', 'hs', 'legal', 'free',
                            'start_add_yes', 'start_add_no', 'upload_yes', 'upload_no',
                            'continue_add_yes', 'continue_add_no'],
                        'description' => 'The button to press on the caller\'s behalf, when what they want is a '
                            .'task with its own mode rather than a question. Use "add" to start posting or creating '
                            .'a load, "storage" for warehousing, "tracking" to follow a shipment, "booking" to take '
                            .'or reserve a load, "hs" for tariff classification, "legal" for customs, duty, VAT or '
                            .'transport law, and the yes/no ones to answer a choice Lena has just offered. Send it '
                            .'on its own, with "question" left out - never describe the task in "question" instead '
                            .'of pressing the button, because a description only talks about the task while the '
                            .'button actually starts it. Once the mode is open, go back to using "question" for '
                            .'each of the caller\'s answers.',
                    ],
                ],
                'required' => [],
                'additionalProperties' => false,
            ],
        ]];
    }

    /**
     * The call agent's prompt, trained like every other Lena skill: agents/lena/call/AGENT.md is the
     * agent itself and agents/lena/call/skills/*.md are its subskills, so the skills screen can edit
     * what the voice does without a deploy. The hard-coded text below is only the floor - it keeps
     * calls working if the tree is ever missing or unreadable.
     */

    /**
     * Prices one finished call and writes the result onto the row minted() logged.
     *
     * The realtime API bills per token, but a call's tokens only exist once it is over: the mint
     * request that opened the log row happened before a word was said. The app therefore reports
     * what the model itself declared in its response.done events, and that is priced here rather
     * than in the browser, so the rates are not something a client can argue with.
     *
     * @param array{audio_input?:int,audio_output?:int,cached_audio_input?:int,text_input?:int,text_output?:int} $usage
     */
    public function recordUsage(array $usage, ?int $conversationId, ?int $userId, ?int $durationMs = null): void
    {
        $rates = (array) config('services.openai.realtime_rates');
        $tokens = [
            'audio_input' => max(0, (int) ($usage['audio_input'] ?? 0)),
            'audio_output' => max(0, (int) ($usage['audio_output'] ?? 0)),
            'cached_audio_input' => max(0, (int) ($usage['cached_audio_input'] ?? 0)),
            'text_input' => max(0, (int) ($usage['text_input'] ?? 0)),
            'text_output' => max(0, (int) ($usage['text_output'] ?? 0)),
        ];

        $cost = 0.0;
        foreach ($tokens as $kind => $count) {
            $cost += $count / 1_000_000 * (float) ($rates[$kind] ?? 0);
        }

        $promptTokens = $tokens['audio_input'] + $tokens['cached_audio_input'] + $tokens['text_input'];
        $completionTokens = $tokens['audio_output'] + $tokens['text_output'];

        // The row this call already owns, rather than a second one: a call is one line on the AI
        // stats screen, opened when it was placed and completed when it ends.
        $log = AiCallLog::query()
            ->where('service', 'realtime_session')
            ->when($conversationId, fn ($query) => $query->where('conversation_id', $conversationId))
            ->when($userId, fn ($query) => $query->where('user_id', $userId))
            ->whereNull('cost_usd')
            ->latest('id')
            ->first();

        if (! $log) return;

        $log->update([
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'cost_usd' => round($cost, 6),
            'duration_ms' => $durationMs ?? $log->duration_ms,
            'response_payload' => [...(array) $log->response_payload, 'usage' => $tokens],
        ]);
    }
    private function instructions(string $language): string
    {
        $spoken = self::LANGUAGE_NAMES[$language] ?? 'English';

        return implode("\n\n", array_filter([
            'You are Lena, the voice of Freightbook.ai, on a phone call with a driver or dispatcher.',
            "Speak {$spoken}, and keep speaking {$spoken} unless the caller clearly switches language.",
            $this->trainedInstructions($spoken) ?: $this->fallbackInstructions($spoken),
        ]));
    }

    /** AGENT.md first, then each subskill in name order, with the Name sections stripped off. */
    private function trainedInstructions(string $spoken): string
    {
        $root = realpath(__DIR__.'/../../agents/lena/call');
        if (! $root) return '';

        $paths = array_merge(
            is_readable($root.'/AGENT.md') ? [$root.'/AGENT.md'] : [],
            glob($root.'/skills/*.md') ?: [],
        );

        $parts = [];
        foreach ($paths as $path) {
            $resolved = realpath($path);
            // Never read outside the call agent's own folder, whatever the glob turns up.
            if (! $resolved || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) || ! is_readable($resolved)) continue;
            $prompt = LenaModeInstructions::split((string) file_get_contents($resolved))['prompt'];
            // Front matter is catalogue metadata for the skills screen, not something to speak.
            $prompt = trim((string) preg_replace('/\A---\R.*?\R---\R/s', '', $prompt));
            if ($prompt !== '') $parts[] = str_replace('{language}', $spoken, $prompt);
        }

        return implode("\n\n", $parts);
    }

    private function fallbackInstructions(string $spoken): string
    {
        return implode("\n\n", [
            'YOU ARE LENA, the freight dispatcher at Freightbook.ai - not an assistant who works with her. '
                .'Never speak about Lena in the third person and never say you are checking with her. The '
                .'freightbook_lookup tool is your own records and systems, so what it returns is your own knowledge: '
                .'say "it is in Graz", never "Lena says it is in Graz". Look things up rather than guess, and never '
                .'invent a shipment status, a price, a location, a tariff number, a legal rule or a reference number.',

            "Before calling the tool, say one short line so the caller is not left in silence - \"let me check that\" "
                ."or its equivalent in {$spoken}. The tool can take a few seconds. Do not narrate the wait further, "
                .'and do not repeat yourself while it runs.',

            'This is speech, not writing. Keep replies to a couple of sentences. Never read out symbols, asterisks, '
                .'bullet markers or anything in double square brackets - say the words instead. Read numbers, '
                .'references and dates the way a person says them aloud. When the tool returns a long answer, give '
                .'the caller the part that matters and offer the rest.',

            'When the tool returns a question - Lena\'s load questionnaire asks one thing at a time - put that '
                .'question to the caller in your own spoken words and send their answer straight back through '
                .'freightbook_lookup. Work through the questionnaire one step at a time, at the caller\'s pace.',

            'The caller is often driving. Be brief, be calm, and let them interrupt you. If they cut you off, stop '
                .'talking immediately and listen. If you did not hear something clearly, ask them to repeat it '
                .'rather than guessing - especially a number.',
        ]);
    }
}
