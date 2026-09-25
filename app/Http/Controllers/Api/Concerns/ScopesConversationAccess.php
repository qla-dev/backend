<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

trait ScopesConversationAccess
{
    /**
     * Restrict a Conversation query to rows the given user created or participates in.
     * Superadmins and masters also manage welcome-page guest conversations in Messages.
     */
    private function scopeConversationToParticipant(Builder $query, ?int $userId): void
    {
        $canManageGuests = $userId && User::query()->whereKey($userId)
            ->whereHas('role', fn (Builder $role) => $role->whereIn('name', ['superadmin', 'master']))->exists();
        $query->where(function (Builder $scope) use ($userId, $canManageGuests): void {
            $scope->where('created_by_user_id', $userId)
                ->orWhereHas('participants', fn (Builder $participants) => $participants->where('users.id', $userId));
            if ($canManageGuests) {
                $scope->orWhereHas('creator', fn (Builder $creator) => $creator->where('email', 'like', '%@lena-guest.invalid'));
            }
        });
    }

    private function userIsConversationParticipant(int $conversationId, ?int $userId): bool
    {
        $query = Conversation::query()->whereKey($conversationId);
        $this->scopeConversationToParticipant($query, $userId);

        return $query->exists();
    }
}
