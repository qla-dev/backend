<?php

namespace App\Http\Controllers\Api;

use App\Models\Conversation;

class ConversationController extends CrudController
{
    protected function modelClass(): string
    {
        return Conversation::class;
    }

    protected function relations(): array
    {
        return ['company', 'freightLoad', 'creator', 'participants', 'messages.sender'];
    }

    protected function searchColumns(): array
    {
        return ['subject', 'channel'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'load_id' => ['nullable', 'integer', 'exists:loads,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'channel' => ['sometimes', 'in:inapp,whatsapp,telegram'], 'subject' => ['nullable', 'string', 'max:255'], 'last_message_at' => ['nullable', 'date']];
    }
}
