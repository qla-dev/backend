<?php

namespace App\Http\Controllers\Api;

use App\Services\LenaSpeech;
use App\Services\SpeechUsageReconciler;
use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Throwable;

class LenaSpeechController extends Controller
{
    use ScopesConversationAccess;
    public function store(Request $request, LenaSpeech $speech)
    {
        $validated = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'lang' => ['required', 'in:en,de,bs,hr,sr'],
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
        ]);
        if (! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.'], 403);
        }
        $text = trim(preg_replace('/\[\[[\s\S]*?\]\]/u', '', $validated['text']));
        if ($text === '') return response()->json(['message' => 'There is no text to read.'], 422);

        try {
            return response($speech->synthesize($text, $validated['conversation_id'], $validated['lang']), 200, [
                'Content-Type' => 'audio/wav',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (Throwable $exception) {
            return response()->json(['message' => 'Speech is temporarily unavailable. Please try again.'], 502);
        } finally {
            if ($speech->generationId) {
                $generationId = $speech->generationId;
                // Fetch billing after delivering audio, so accounting does not delay playback.
                app()->terminating(fn () => app(SpeechUsageReconciler::class)->reconcile($generationId));
            }
        }
    }
}
