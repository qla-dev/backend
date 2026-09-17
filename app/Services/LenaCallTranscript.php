<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes what was actually said on a call into the conversation it belongs to.
 *
 * A call in free conversation never reaches dispatch-chat - the realtime model answers out of its
 * own head, which is the point of it being fast - so nothing else would ever write the thread. The
 * caller hangs up and the conversation is empty.
 *
 * Both sides are stored as ordinary messages, so the thread reads afterwards exactly like a typed
 * one and can be continued by typing. Which user a turn is attributed to is decided here from a
 * speaker flag, never from an id the app sends, so a client cannot post messages as Lena.
 */
class LenaCallTranscript
{
    /** Spoken turns arrive one sentence at a time; anything shorter than this is a stray noise. */
    private const MIN_LENGTH = 2;

    public function save(int $conversationId, string $speaker, string $text, ?int $callerUserId): bool
    {
        // Whisper invents a subtitle sign-off out of silence; it must never reach the thread.
        $body = SpeechHallucinations::strip($text);
        if (mb_strlen($body) < self::MIN_LENGTH) return false;

        $senderId = $speaker === 'lena'
            ? User::query()->where('username', 'ai_dispatcher')->value('id')
            : $callerUserId;

        if (! $senderId) return false;

        // A transcript is a record of a call, not a reason to fail one: a turn that cannot be
        // stored must never surface as an error in the middle of someone talking.
        try {
            // The realtime API re-sends a turn's transcript on reconnect, and a caller who repeats
            // themselves genuinely says the same words twice in a row. Neither should double up.
            $duplicate = Message::query()
                ->where('conversation_id', $conversationId)
                ->where('sender_user_id', $senderId)
                ->where('body', $body)
                ->where('created_at', '>=', now()->subSeconds(30))
                ->exists();

            if ($duplicate) return false;

            Message::query()->create([
                'conversation_id' => $conversationId,
                'sender_user_id' => $senderId,
                'body' => $body,
                'sent_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::warning('A call transcript turn could not be saved.', [
                'conversation_id' => $conversationId,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        return true;
    }
}
