<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;

trait ScopesConversationAccess
{
    /**
     * Restrict a Conversation query to rows the given user created or participates in.
     * A user must never see, reply into, or trigger AI replies on a conversation they are not part of.
     */
    private function scopeConversationToParticipant(Builder $query, ?int $userId): void
    {
        $query->where(function (Builder $scope) use ($userId): void {
            $scope->where('created_by_user_id', $userId)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->where('users.id', $userId));
        });
    }

    private function userIsConversationParticipant(int $conversationId, ?int $userId): bool
    {
        $query = Conversation::query()->whereKey($conversationId);
        $this->scopeConversationToParticipant($query, $userId);

        return $query->exists();
    }
}
