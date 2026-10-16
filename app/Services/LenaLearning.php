<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\LenaLearningSession;
use App\Models\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LenaLearning
{
    public function reply(Conversation $conversation, int $userId, int $aiId, string $locale): Message
    {
        // Learning is private to the learner, rather than shared with human participants.
        abort_unless((int) $conversation->created_by_user_id === $userId
            && ! $conversation->load_id && ! $conversation->load_draft_id
            && ! $conversation->participants()->whereNotIn('users.id', [$userId, $aiId])->exists(), 403);
        $text = trans('lena-learning', [], $locale);
        if (! Schema::hasTable('lena_learning_sessions')) {
            throw ValidationException::withMessages(['learning' => [$text['unavailable']]]);
        }

        return DB::transaction(function () use ($conversation, $userId, $aiId, $text, $locale): Message {
            Conversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $input = $conversation->messages()->where('sender_user_id', $userId)->reorder()->orderByDesc('id')->firstOrFail();
            $session = LenaLearningSession::query()->firstOrCreate(
                ['conversation_id' => $conversation->id, 'user_id' => $userId, 'program' => LenaLearningEngine::PROGRAM],
                ['version' => LenaLearningEngine::VERSION, 'state' => (new LenaLearningEngine)->initial()],
            );
            abort_unless(in_array($session->version, [1, LenaLearningEngine::VERSION], true), 409);
            if ($session->version === LenaLearningEngine::VERSION && $session->last_user_message_id === $input->id && $session->reply_message_id) {
                return Message::query()->findOrFail($session->reply_message_id)->load('sender');
            }
            [$body, $state] = app(LenaLearningTutor::class)->generate($conversation, $aiId, $locale, $session->state);
            $message = Message::query()->create([
                'conversation_id' => $conversation->id, 'sender_user_id' => $aiId,
                'body' => $body, 'sent_at' => now(),
            ]);
            $session->update(['version' => LenaLearningEngine::VERSION, 'state' => $state, 'last_user_message_id' => $input->id, 'reply_message_id' => $message->id]);
            $conversation->update(['canvas' => false, 'last_message_at' => $message->sent_at,
                'subject' => 'AI Dispatch — '.$text['title']]);
            $conversation->participants()->syncWithoutDetaching([$aiId]);
            return $message->load('sender');
        });
    }
}
