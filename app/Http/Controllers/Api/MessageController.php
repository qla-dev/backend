<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Resources\EntityResource;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MessageController extends CrudController
{
    use ScopesConversationAccess;

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

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $userId = $request->user()?->id;

        if ((int) $data['sender_user_id'] !== $userId) {
            throw ValidationException::withMessages(['sender_user_id' => ['You can only send messages as yourself.']]);
        }

        if (! $this->userIsConversationParticipant((int) $data['conversation_id'], $userId)) {
            throw ValidationException::withMessages(['conversation_id' => ['You are not part of this conversation.']]);
        }

        $record = Message::query()->create($data);
        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource created successfully.', status: 201);
    }
}
