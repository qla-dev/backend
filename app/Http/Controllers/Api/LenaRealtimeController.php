<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
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
