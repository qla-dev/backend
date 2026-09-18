<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use App\Services\LenaCallTranscript;
use App\Services\LenaSkillCatalog;
use App\Services\LenaSkillSelector;
use App\Services\LenaRealtimeSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Hands the app a short-lived credential for one live call with Lena, and nothing more.
 *
 * The permanent OpenAI key stays on the server. The app receives only an ephemeral client secret,
 * which it spends immediately on its own SDP exchange and which expires on its own. The tool the
 * call may use is fixed server-side in LenaRealtimeSession, so a tampered client cannot widen what
 * the model is allowed to do - and the tool itself runs back through this API under the caller's
 * own bearer token, which is what keeps a call inside the data that caller could already see.
 */
class LenaRealtimeController extends Controller
{
    use ScopesConversationAccess;

    /** Whether a live call can be started at all, so the app can hide or disable the button. */
    public function status(LenaRealtimeSession $session): JsonResponse
    {
        return response()->json(['data' => ['configured' => $session->isConfigured()]]);
    }



    /**
     * Saves one spoken turn of a call into the conversation, as it was actually said.
     *
     * A call in free conversation makes no dispatch-chat round trip - the realtime model answers
     * out of its own head - so without this the thread would be empty afterwards and the call
     * would leave nothing behind. Writing it here rather than from the browser is what keeps the
     * app from being able to post messages as Lena: the speaker is a flag, not a user id.
     */
    public function transcript(Request $request, LenaCallTranscript $transcript): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'speaker' => ['required', 'in:caller,lena'],
            'text' => ['required', 'string', 'max:5000'],
        ]);

        if (! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.'], 403);
        }

        $saved = $transcript->save(
            (int) $validated['conversation_id'],
            $validated['speaker'],
            $validated['text'],
            $request->user()?->id,
        );

        return response()->json(['data' => ['saved' => $saved]]);
    }

    /**
     * The skills behind a mode, as prompt text a live call can be given mid-conversation.
     *
     * The text chat picks skills per message, because every message is a round trip. A realtime
     * session has no such moment in free conversation - but it can be reconfigured at any time with
     * session.update, so when a call enters a task its skills are fetched here and pushed into the
     * live session. She gets the same knowledge the typed path would have used, a beat later.
     */
    public function modeSkills(Request $request, LenaSkillCatalog $catalog, LenaSkillSelector $selector): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:freeroam,add,storage,tracking,booking,hs,legal,free,training'],
        ]);

        // The button a caller presses is not always the folder its skills live in.
        $folder = ['add' => 'post-load', 'free' => 'free', 'freeroam' => 'freeroam'][$validated['mode']] ?? $validated['mode'];

        $files = collect($catalog->rows())
            // A mode's own subskills, plus the shared ones every mode loads. Not the AGENT.md files:
            // those carry the text chat's own protocol - its buttons and markers - which is the one
            // thing a voice must never start producing.
            ->filter(fn (array $row) => $row['kind'] === 'skill' && in_array($folder, $row['modes'], true))
            ->pluck('id')
            ->values()
            ->all();

        return response()->json(['data' => [
            'mode' => $validated['mode'],
            'skills' => $files,
            'instructions' => $selector->instructions($files),
        ]]);
    }
    /**
     * Prices a finished call. The app reports what the model declared during the session, because
     * the mint request that opened the log row happened before any of it existed.
     */
    public function usage(Request $request, LenaRealtimeSession $session): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'usage' => ['required', 'array'],
            'usage.audio_input' => ['nullable', 'integer', 'min:0'],
            'usage.audio_output' => ['nullable', 'integer', 'min:0'],
            'usage.cached_audio_input' => ['nullable', 'integer', 'min:0'],
            'usage.text_input' => ['nullable', 'integer', 'min:0'],
            'usage.text_output' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['conversation_id'])
            && ! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.'], 403);
        }

        $session->recordUsage(
            $validated['usage'],
            $validated['conversation_id'] ?? null,
            $request->user()?->id,
            $validated['duration_ms'] ?? null,
        );

        return response()->json(['data' => ['recorded' => true]]);
    }

    /**
     * Prices a finished call. The app reports what the model declared during the session, because
     * the mint request that opened the log row happened before any of it existed.
     */
    public function usage(Request $request, LenaRealtimeSession $session): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
            'duration_ms' => ['nullable', 'integer', 'min:0'],
            'usage' => ['required', 'array'],
            'usage.audio_input' => ['nullable', 'integer', 'min:0'],
            'usage.audio_output' => ['nullable', 'integer', 'min:0'],
            'usage.cached_audio_input' => ['nullable', 'integer', 'min:0'],
            'usage.text_input' => ['nullable', 'integer', 'min:0'],
            'usage.text_output' => ['nullable', 'integer', 'min:0'],
        ]);

        if (isset($validated['conversation_id'])
            && ! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.'], 403);
        }

        $session->recordUsage(
            $validated['usage'],
            $validated['conversation_id'] ?? null,
            $request->user()?->id,
            $validated['duration_ms'] ?? null,
        );

        return response()->json(['data' => ['recorded' => true]]);
    }
    public function store(Request $request, LenaRealtimeSession $session): JsonResponse
    {
        $validated = $request->validate([
            'lang' => ['required', 'in:en,de,bs,hr,sr'],
            // Optional: a call can be started before any conversation row exists, exactly like the
            // first typed message in a new chat. When supplied it is enforced, so a call can never
            // be attached to a conversation the caller is not part of.
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ]);

        if (isset($validated['conversation_id'])
            && ! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.'], 403);
        }

        if (! $session->isConfigured()) {
            // 503 rather than 500: this is a deployment that has not been given a key yet, not a
            // fault. The app shows it as "calling is not set up", not as an error.
            return response()->json(['message' => 'Calling Lena is not set up on this server yet.'], 503);
        }

        try {
            $minted = $session->mint($validated['lang'], $validated['conversation_id'] ?? null, $request->user()?->id);
        } catch (Throwable $exception) {
            return response()->json(['message' => 'The call could not be started. Please try again.'], 502);
        }

        return response()->json(['data' => $minted]);
    }
}
