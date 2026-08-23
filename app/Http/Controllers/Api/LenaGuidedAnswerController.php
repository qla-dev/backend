<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\AiCallLogger;
use App\Services\LenaGuidedAnswerResponder;
use App\Services\LenaLoadQuestionnaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Handles questionnaire steps the user answered by clicking one of the offered pills (a fixed,
// already-known value, or "choose later") instead of typing free text. Because the value is
// already known and valid, this never calls OpenRouter: the draft field is set deterministically,
// a human-sounding confirmation is built from LenaGuidedAnswerResponder, and a $0 audit row is
// still written to ai_call_logs so AI Stats keeps a complete record of every questionnaire turn.
// Free-text answers (anything typed instead of clicked) still go through DispatchChatController
// + the OpenRouterLoadScanner AI pipeline as before, since those need real normalization.
class LenaGuidedAnswerController extends Controller
{
    use ScopesConversationAccess;

    // Only steps the frontend renders as real selectable pills (see questionnaireSuggestions() in
    // useLenaEmbeddedMessages.tsx) are handled here; everything else must go through the AI path.
    private const MULTI_VALUE_STEPS = ['specialRequirements', 'requirements'];

    private const REQUIREMENT_FIELDS = [
        'ADR' => 'requiresAdr',
        'Tail lift' => 'requiresTailLift',
        'Priority load' => 'isUrgent',
        'Toll roads' => 'tollRoadsIncluded',
        'Ferry' => 'ferryIncluded',
        'CMR' => 'cmrRequired',
        'Pallet exchange' => 'palletExchangeRequired',
        'Customs' => 'customsRequired',
        'Insurance' => 'insuranceRequired',
        'Certification' => 'certificationRequired',
        'Inspection services' => 'inspectionServicesRequired',
        'Must be trackable' => 'requiresTracking',
    ];

    public function store(Request $request, LenaLoadQuestionnaire $questionnaire, LenaGuidedAnswerResponder $responder, AiCallLogger $logger): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
            'step' => ['required', 'string', 'in:transportType,bodyType,vehicleType,loadingEquipment,characteristics,specialRequirements,transportMode,deliveryProof,priceTerms,terms,requirements,contact'],
            'value' => ['nullable', 'string'],
            'display_text' => ['required', 'string'],
            'skip' => ['required', 'boolean'],
            'lang' => ['nullable', 'string', 'in:bs,de,en'],
        ]);
        $lang = $validated['lang'] ?? 'en';
        $skip = $validated['skip'];

        $user = $request->user();
        if (! $this->userIsConversationParticipant($validated['conversation_id'], $user?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.', 'data' => null, 'meta' => [], 'errors' => []], 403);
        }

        $aiDispatcherId = User::query()->where('username', 'ai_dispatcher')->value('id');
        if (! $aiDispatcherId) {
            return response()->json(['message' => 'AI dispatcher is not configured.', 'data' => null, 'meta' => [], 'errors' => []], 503);
        }

        $conversation = Conversation::query()->with('messages')->findOrFail($validated['conversation_id']);
        $startedAt = microtime(true);

        $draft = $this->latestLoadDraft($conversation->messages);
        if (! $skip) {
            $draft = $this->applyAnswer($draft, $validated['step'], (string) $validated['value'], $user);
        }
        $draft['isDocument'] = true;

        $userMessageBody = $skip ? "[[LENA_SKIP:{$validated['step']}]]" : $validated['display_text'];
        $userMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $user->id,
            'body' => $userMessageBody,
            'attachments' => [[
                'name' => 'LenaAI conversation',
                'type' => 'text/plain',
                'size' => strlen(json_encode($draft, JSON_UNESCAPED_UNICODE)),
                'loadScan' => $draft,
            ]],
            'sent_at' => now(),
        ]);

        $messagesForStep = $conversation->messages->push($userMessage);
        $nextStep = $questionnaire->nextStep($draft, $messagesForStep, (int) $aiDispatcherId);
        // "Current user" resolves to the actual account name in the draft (see applyAnswer) - the
        // confirmation sentence should reflect that same resolved value, not the literal pill label.
        $confirmedValue = $validated['step'] === 'contact' && $validated['value'] === 'Current user'
            ? ($draft['contactName'] ?? $validated['display_text'])
            : $validated['display_text'];
        $replyText = $responder->respond($validated['step'], $lang, $skip ? null : $confirmedValue, $nextStep);

        $assistantMessage = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $aiDispatcherId,
            'body' => $replyText,
            'sent_at' => now()->addMillisecond(),
        ]);

        $conversation->update(['last_message_at' => $assistantMessage->sent_at]);
        $conversation->participants()->syncWithoutDetaching([$aiDispatcherId]);
        $assistantMessage->load('sender');

        $logger->record([
            'service' => 'guided_answer',
            'conversation_id' => $conversation->id,
            'model' => 'freightbook/lena-1.0-alpha',
            'has_attachment' => false,
            'is_success' => true,
            'request_payload' => ['step' => $validated['step'], 'value' => $skip ? null : $validated['value'], 'skip' => $skip, 'lang' => $lang],
            'response_payload' => ['reply' => $replyText],
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            'cost_usd' => 0,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'http_status' => 200,
        ]);

        return response()->json([
            'message' => 'Reply generated.',
            'data' => (new EntityResource($assistantMessage))->resolve($request),
            'meta' => [],
            'errors' => [],
        ], 201);
    }

    private function applyAnswer(array $draft, string $step, string $value, ?User $user): array
    {
        if (in_array($step, self::MULTI_VALUE_STEPS, true)) {
            $values = array_filter(array_map('trim', explode(',', $value)));

            if ($step === 'specialRequirements') {
                $draft['specialRequirements'] = array_values($values);
            } else {
                foreach (self::REQUIREMENT_FIELDS as $label => $field) {
                    $draft[$field] = in_array($label, $values, true);
                }
            }

            return $draft;
        }

        return match ($step) {
            'transportType' => [...$draft, 'transportType' => $value],
            'bodyType' => [...$draft, 'bodyType' => $value],
            'vehicleType' => [...$draft, 'vehicleType' => $value],
            'loadingEquipment' => [...$draft, 'loadingEquipment' => $value],
            'characteristics' => [...$draft, 'characteristics' => $value],
            'transportMode' => [...$draft, 'transportMode' => $value],
            'deliveryProof' => [...$draft, 'deliveryProof' => $value],
            'priceTerms' => [...$draft, 'priceTerms' => $value],
            'terms' => [...$draft, 'incoterm' => $value],
            'contact' => [...$draft, 'contactName' => $value === 'Current user' ? ($user?->name ?? $value) : $value],
            default => $draft,
        };
    }

    private function latestLoadDraft(Collection $messages): array
    {
        foreach ($messages->sortByDesc('sent_at') as $message) {
            foreach (array_reverse($message->attachments ?? []) as $attachment) {
                if (is_array($attachment) && is_array($attachment['loadScan'] ?? null)) {
                    return $attachment['loadScan'];
                }
            }
        }

        return [];
    }
}
