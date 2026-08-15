<?php

namespace App\Http\Controllers\Api;

use App\Models\Message;

class MessageController extends CrudController
{
    protected function modelClass(): string
    {
        return Message::class;
    }

    protected function relations(): array
    {
        return ['conversation', 'sender'];
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['conversation_id' => [$p, 'integer', 'exists:conversations,id'], 'sender_user_id' => [$p, 'integer', 'exists:users,id'], 'body' => [$p, 'string'], 'attachments' => ['nullable', 'array'], 'sent_at' => [$p, 'date'], 'edited_at' => ['nullable', 'date']];
    }
}
