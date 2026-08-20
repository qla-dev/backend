<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends CrudController
{
    use ScopesConversationAccess;

    protected function modelClass(): string
    {
        return Conversation::class;
    }

    protected function relations(): array
    {
        return ['company', 'freightLoad.consignee', 'freightLoad.stops', 'creator', 'participants.role', 'messages.sender'];
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

        $this->scopeConversationToParticipant($query, $request->user()?->id);
    }

    protected function rules(bool $u = false): array
    {
        $p = $u ? 'sometimes' : 'required';

        return ['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'load_id' => ['nullable', 'integer', 'exists:loads,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'channel' => ['sometimes', 'in:inapp,whatsapp,telegram'], 'subject' => ['nullable', 'string', 'max:255'], 'canvas' => ['sometimes', 'boolean'], 'last_message_at' => ['nullable', 'date'], 'participant_ids' => ['sometimes', 'array'], 'participant_ids.*' => ['integer', 'exists:users,id']];
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

    public function show(Request $request, int $id): JsonResponse
    {
        $query = Conversation::query()->with($this->relations());
        $this->scopeConversationToParticipant($query, $request->user()?->id);
        $record = $query->findOrFail($id);

        return $this->success((new EntityResource($record))->resolve($request), 'Resource retrieved successfully.');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $query = Conversation::query();
        $this->scopeConversationToParticipant($query, $request->user()?->id);
        $record = $query->findOrFail($id);
        $data = $request->validate($this->rules(true));
        $participantIds = $data['participant_ids'] ?? null;
        unset($data['participant_ids']);
        $record->update($data);
        if (is_array($participantIds)) {
            $record->participants()->syncWithoutDetaching($participantIds);
        }
        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource updated successfully.');
    }
}
