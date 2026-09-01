<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use App\Models\LoadDraft;
use App\Models\Message;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\AiCallLogger;
use App\Services\LenaGuidedAnswerResponder;
use App\Services\LenaLoadQuestionnaire;
use App\Services\LoadDraftScanMapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

// Handles questionnaire steps the user answered with an already-unambiguous value - either a
// clicked pill (a fixed, already-known value, or "choose later"), or free text typed through one
// of the chat input's regex-constrained fields (see lenaStepInputMask.ts: weight/pallets are
// digits-only, dimensions is a strict LxWxH digit/x pattern, budget/declaredValue are decimal-only
// - so by the time it reaches here there is nothing left for an AI to normalize). Because the
// value is already known and valid, this never calls OpenRouter: the draft field is set
// deterministically, a human-sounding confirmation is built from LenaGuidedAnswerResponder, and a
// $0 audit row is still written to ai_call_logs so AI Stats keeps a complete record of every turn.
// Every other free-text step (title, goodsType, pickup/delivery, notes, ...) still goes through
// DispatchChatController + the OpenRouterLoadScanner AI pipeline, since those genuinely need real
// language understanding to normalize.
class LenaGuidedAnswerController extends Controller
{
    use ScopesConversationAccess;

    // Multi-select pill steps only - a comma-joined list of chosen labels, not a single value.
    private const MULTI_VALUE_STEPS = ['specialRequirements', 'requirements', 'characteristics'];

    // The steps applyAnswer() actually knows how to write into the draft (pill steps + the
    // regex-masked numeric/date ones). Every other step is skip-only here.
    private const VALUE_CAPABLE_STEPS = [
        'storageTarget', 'warehouse', 'transportType', 'bodyType', 'vehicleType', 'loadingEquipment', 'characteristics',
        'specialRequirements', 'transportMode', 'deliveryProof', 'priceTerms', 'terms',
        'requirements', 'contact', 'weight', 'pallets', 'dimensions', 'budget', 'declaredValue',
        'pickupDate', 'deliveryDate',
    ];

    private const DATE_STEPS = ['pickupDate', 'deliveryDate'];

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
            // Every LenaLoadQuestionnaire::STEPS key is accepted here, even the plain free-text
            // ones with no value-setting logic below (title, goodsType, pickup, delivery, notes,
            // temperature) - those are only ever reachable through the universal "later" pill (see
            // questionnaireSuggestions()' withLater([]) default), so they only ever arrive here
            // with skip:true, guarded just below.
            'step' => ['required', 'string', 'in:storageTarget,warehouse,title,transportType,goodsType,weight,pallets,bodyType,dimensions,vehicleType,loadingEquipment,characteristics,specialRequirements,transportMode,deliveryProof,pickup,pickupDate,delivery,deliveryDate,budget,priceTerms,declaredValue,terms,temperature,requirements,contact,notes'],
            'value' => ['nullable', 'string'],
            'display_text' => ['required', 'string'],
            'skip' => ['required', 'boolean'],
            'lang' => ['nullable', 'string', 'in:bs,de,en'],
        ]);
        $lang = $validated['lang'] ?? 'en';
        $skip = $validated['skip'];

        if (! $skip && ! in_array($validated['step'], self::VALUE_CAPABLE_STEPS, true)) {
            return response()->json(['message' => 'This step can only be skipped here, not answered.', 'data' => null, 'meta' => [], 'errors' => []], 422);
        }

        // The frontend mask already constrains keystrokes to DD.MM.YYYY, but a structurally valid
        // string can still name a calendar date that doesn't exist (e.g. 31.02.2026) - reject that
        // here rather than silently confirming a value that was never actually saved.
        if (! $skip && in_array($validated['step'], self::DATE_STEPS, true) && $this->parseGuidedDate((string) $validated['value']) === null) {
            return response()->json(['message' => 'Please enter a valid date in DD.MM.YYYY format.', 'data' => null, 'meta' => [], 'errors' => []], 422);
        }

        $user = $request->user();
        if (! $this->userIsConversationParticipant($validated['conversation_id'], $user?->id)) {
            return response()->json(['message' => 'You are not part of this conversation.', 'data' => null, 'meta' => [], 'errors' => []], 403);
        }

        $aiDispatcherId = User::query()->where('username', 'ai_dispatcher')->value('id');
        if (! $aiDispatcherId) {
            return response()->json(['message' => 'AI dispatcher is not configured.', 'data' => null, 'meta' => [], 'errors' => []], 503);
        }

        $conversation = Conversation::query()->with(['messages', 'freightLoadDraft'])->findOrFail($validated['conversation_id']);
        $startedAt = microtime(true);

        $draft = $this->latestLoadDraft($conversation->messages);
        if ($draft === [] && $conversation->freightLoadDraft) {
            $draft = app(LoadDraftScanMapper::class)->toScan($conversation->freightLoadDraft);
        }
        if (! $skip) {
            $draft = $this->applyAnswer($draft, $validated['step'], (string) $validated['value'], $user);
        }
        $draft['isDocument'] = true;
        $this->persistStorageDraft($conversation, $draft);

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
            'request_payload' => ['step' => $validated['step'], 'value' => $skip ? null : $validated['value'], 'display_text' => $validated['display_text'], 'skip' => $skip, 'lang' => $lang],
            'response_payload' => ['reply' => $replyText],
            'prompt_tokens' => 0,
            'completion_tokens' => 0,
            'total_tokens' => 0,
            // A flat notional rate, not a real OpenRouter charge (there is none - see the class
            // docblock) - keeps this service showing a non-zero cost in AI Stats' totals instead
            // of looking like a free-for-everyone loophole.
            'cost_usd' => 0.01,
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
            } elseif ($step === 'characteristics') {
                $draft['characteristics'] = array_values($values);
            } else {
                foreach (self::REQUIREMENT_FIELDS as $label => $field) {
                    $draft[$field] = in_array($label, $values, true);
                }
            }

            return $draft;
        }

        if ($step === 'dimensions') {
            return $this->applyDimensions($draft, $value);
        }

        if ($step === 'warehouse') {
            $warehouse = $this->accessibleWarehouse($user, (int) $value);
            abort_unless($warehouse, 422, 'The selected warehouse is not available to this account.');

            return [...$draft,
                'storageTarget' => 'own',
                'warehouseId' => $warehouse->id,
                'warehouseName' => $warehouse->name,
                'deliveryPlaceType' => 'Warehouse',
                'deliveryCity' => (string) ($warehouse->city ?? ''),
                'deliveryCountryCode' => (string) ($warehouse->country_code ?? ''),
                'deliveryAddress' => (string) ($warehouse->address ?? ''),
                'deliveryLatitude' => $warehouse->latitude,
                'deliveryLongitude' => $warehouse->longitude,
            ];
        }

        if ($step === 'storageTarget') {
            abort_unless(in_array($value, ['own', 'exchange'], true), 422, 'Invalid storage destination.');
        }

        return match ($step) {
            'storageTarget' => [...$draft, 'storageTarget' => $value, 'warehouseId' => $value === 'exchange' ? null : ($draft['warehouseId'] ?? null)],
            'transportType' => [...$draft, 'transportType' => $value],
            'bodyType' => [...$draft, 'bodyType' => $value],
            'vehicleType' => [...$draft, 'vehicleType' => $value],
            'loadingEquipment' => [...$draft, 'loadingEquipment' => $value],
            'transportMode' => [...$draft, 'transportMode' => $value],
            'deliveryProof' => [...$draft, 'deliveryProof' => $value],
            'priceTerms' => [...$draft, 'priceTerms' => $value],
            'terms' => [...$draft, 'incoterm' => $value],
            'contact' => [...$draft, 'contactName' => $value === 'Current user' ? ($user?->name ?? $value) : $value],
            'weight' => [...$draft, 'weightKg' => (float) $value],
            'pallets' => [...$draft, 'pallets' => (int) $value],
            'budget' => [...$draft, 'budget' => (float) $value],
            'declaredValue' => [...$draft, 'declaredValue' => (float) $value],
            'pickupDate' => [...$draft, 'pickupDate' => $this->parseGuidedDate($value) ?? $draft['pickupDate'] ?? null],
            'deliveryDate' => [...$draft, 'deliveryDate' => $this->parseGuidedDate($value) ?? $draft['deliveryDate'] ?? null],
            default => $draft,
        };
    }

    private function accessibleWarehouse(?User $user, int $warehouseId): ?Warehouse
    {
        if (! $user || $warehouseId < 1) {
            return null;
        }

        $query = Warehouse::query()->whereKey($warehouseId);
        if (! $user->isSuperAdminOrMaster()) {
            $ownerIds = $user->companies()->pluck('companies.owner_user_id')->push($user->id)->unique();
            $query->whereIn('user_id', $ownerIds);
        }

        return $query->first();
    }

    private function persistStorageDraft(Conversation $conversation, array $draft): void
    {
        if (! $conversation->load_draft_id) {
            $conversation->update(['load_draft_id' => LoadDraft::query()->create()->id]);
        }

        $fields = [
            'transport_type' => $draft['transportType'] ?? null,
            'storage_target' => $draft['storageTarget'] ?? null,
            'warehouse_id' => $draft['warehouseId'] ?? null,
            'delivery_place_type' => $draft['deliveryPlaceType'] ?? null,
            'delivery_city' => $draft['deliveryCity'] ?? null,
            'delivery_country_code' => $draft['deliveryCountryCode'] ?? null,
            'delivery_address' => $draft['deliveryAddress'] ?? null,
            'delivery_latitude' => $draft['deliveryLatitude'] ?? null,
            'delivery_longitude' => $draft['deliveryLongitude'] ?? null,
        ];

        LoadDraft::query()->whereKey($conversation->load_draft_id)->update($fields);
    }

    // "05.12.2026" (DD.MM.YYYY, matching the frontend mask) -> "2026-12-05" (the Y-m-d format
    // every other date field in the draft already uses - see RelativeLoadDateResolver). Returns
    // null for anything malformed, calendar-invalid (e.g. 31.02.2026), or outside a sane freight-
    // planning window - the mask alone can't reject a structurally valid but nonsensical year like
    // 2555, so that's bounded here instead. store() rejects a null result with a 422 before ever
    // reaching here.
    private function parseGuidedDate(string $value): ?string
    {
        if (preg_match('/^(\d{2})\.(\d{2})\.(\d{4})$/', trim($value), $match) !== 1) {
            return null;
        }
        [, $day, $month, $year] = $match;
        if (! checkdate((int) $month, (int) $day, (int) $year)) {
            return null;
        }
        $currentYear = (int) now()->format('Y');
        if ((int) $year < $currentYear - 1 || (int) $year > $currentYear + 3) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    // "200x150x180" (length x width x height, meters) - each segment is optional so a partial
    // answer (e.g. just "200") still sets whatever it can, matching how the AI-driven path already
    // treats any single positive dimension as enough to satisfy this step.
    private function applyDimensions(array $draft, string $value): array
    {
        $segments = array_map('trim', explode('x', strtolower($value)));
        $fields = ['lengthM', 'widthM', 'heightM'];

        foreach ($fields as $index => $field) {
            if (isset($segments[$index]) && is_numeric($segments[$index])) {
                $draft[$field] = (float) $segments[$index];
            }
        }

        return $draft;
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
