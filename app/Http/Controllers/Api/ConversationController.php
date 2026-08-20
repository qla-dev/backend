<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends CrudController
{
    protected function modelClass(): string
    {
        return Conversation::class;
    }

    protected function relations(): array
    {
        return ['company', 'freightLoad.consignee', 'creator', 'participants.role', 'messages.sender'];
    }

    protected function searchColumns(): array
    {
        return ['subject', 'channel'];
    }

    protected function applyFilters(Builder $query, Request $request): void
    {
        if ($request->filled('load_id')) {
            $query->where('load_id', $request->integer('load_id'));
        }
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'load_id' => ['nullable', 'integer', 'exists:loads,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'channel' => ['sometimes', 'in:inapp,whatsapp,telegram'], 'subject' => ['nullable', 'string', 'max:255'], 'last_message_at' => ['nullable', 'date'], 'participant_ids' => ['sometimes', 'array'], 'participant_ids.*' => ['integer', 'exists:users,id']];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $participantIds = collect($data['participant_ids'] ?? [])->push($data['created_by_user_id'])->unique()->values();
        unset($data['participant_ids']);

        $record = Conversation::query()->create($data);
        $record->participants()->attach($participantIds);
        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource created successfully.', status: 201);
    }
}
