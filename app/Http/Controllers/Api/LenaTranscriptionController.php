<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use App\Services\LenaTranscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

// Counterpart to LenaSpeechController: that one turns Lena's reply into audio, this one turns the
// driver's recording into text. The text is returned rather than posted as a message, so the app
// can show it in the composer for correction before it is sent - a cab is loud and Whisper is not
// infallible, and a wrong word silently entered into the load questionnaire is worse than a retry.
class LenaTranscriptionController extends Controller
{
    use ScopesConversationAccess;

    // ~2 minutes of m4a at the quality expo-audio records. Base64 inflates by a third, and the
    // request still has to fit inside PHP's post_max_size on the deployed server.
    private const MAX_BASE64_LENGTH = 4_000_000;

    public function store(Request $request, LenaTranscription $transcription): JsonResponse
    {
        $validated = $request->validate([
            'audio' => ['required', 'string', 'max:'.self::MAX_BASE64_LENGTH],
            'format' => ['required', 'in:wav,mp3,m4a,ogg,webm,flac,aac'],
            'lang' => ['required', 'in:en,de,bs,hr,sr'],
            'conversation_id' => ['nullable', 'integer', 'exists:conversations,id'],
        ]);

        // conversation_id is optional because the first thing a driver says can be what *starts*
        // the conversation - there is no row to check yet. When one is supplied it is enforced,
        // so an existing conversation can never be transcribed into by a non-participant.
        if (isset($validated['conversation_id'])
            && ! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.'], 403);
        }

        $audio = base64_decode($validated['audio'], true);
        if ($audio === false || $audio === '') {
            return response()->json(['message' => 'That recording could not be read.'], 422);
        }

        try {
            $text = $transcription->transcribe($audio, $validated['format'], $validated['conversation_id'] ?? null, $validated['lang']);
        } catch (Throwable $exception) {
            // Never surface the provider error: it can carry the request, and the request carries
            // both the credentials and the driver's own voice.
            return response()->json(['message' => 'Speech recognition is temporarily unavailable. Please try again.'], 502);
        }

        return response()->json(['data' => ['text' => $text]]);
    }
}
