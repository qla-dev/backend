<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
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

        return ['company_id' => ['nullable', 'integer', 'exists:companies,id'], 'load_id' => ['nullable', 'integer', 'exists:loads,id'], 'load_draft_id' => ['nullable', 'integer', 'exists:load_drafts,id'], 'created_by_user_id' => [$p, 'integer', 'exists:users,id'], 'channel' => ['sometimes', 'in:inapp,whatsapp,telegram'], 'subject' => ['nullable', 'string', 'max:255'], 'canvas' => ['sometimes', 'boolean'], 'last_message_at' => ['nullable', 'date'], 'participant_ids' => ['sometimes', 'array'], 'participant_ids.*' => ['integer', 'exists:users,id']];
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules());
        $participantIds = collect($data['participant_ids'] ?? [])->push($data['created_by_user_id'])->unique()->values();
        unset($data['participant_ids']);

        $record = Conversation::query()->create($data);
        $record->participants()->attach($participantIds);

        // A conversation created straight from a manually-started PostLoadModal draft (see
        // PostLoadModal.tsx's saveDraft) has no prior chat history to greet the user with, unlike
        // a LenaAI-originated draft which already backfills load_draft_id onto an existing,
        // already-active conversation (DispatchChatController) rather than creating a new one.
        if (! blank($data['load_draft_id'] ?? null)) {
            $this->postDraftCreatedMessage($record, $request->user());
        }

        $record->load($this->relations());

        return $this->success((new EntityResource($record))->resolve($request), 'Resource created successfully.', status: 201);
    }

    private function postDraftCreatedMessage(Conversation $conversation, ?User $user): void
    {
        $aiDispatcherId = User::query()->where('username', 'ai_dispatcher')->value('id');
        if (! $aiDispatcherId) {
            return;
        }

        $reference = $conversation->freightLoadDraft?->booking_reference;
        $suffix = $reference ? ' '.$reference : '';
        $bodies = [
            'bs' => "Čestitamo, kreirali ste draft tereta{$suffix}! Možete nastaviti razgovor ovdje da ga dopunite, ili se vratiti kasnije da ga završite i objavite.",
            'de' => "Herzlichen Glückwunsch, Sie haben einen Ladungsentwurf{$suffix} erstellt! Sie können hier weiterchatten, um ihn zu vervollständigen, oder später zurückkehren, um ihn fertigzustellen und zu veröffentlichen.",
            'en' => "Congratulations, you created a load draft{$suffix}! You can keep chatting here to fill it in, or come back later to finish and post it.",
        ];
        $body = $bodies[$user?->language] ?? $bodies['en'];

        Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $aiDispatcherId,
            'body' => $body,
            'sent_at' => now(),
        ]);
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
