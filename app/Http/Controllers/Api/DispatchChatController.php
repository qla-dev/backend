<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use App\Models\Load;
use App\Models\Message;
use App\Models\User;
use App\Services\OpenRouterDispatchAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DispatchChatController extends Controller
{
    use ScopesConversationAccess;

    public function store(Request $request, OpenRouterDispatchAssistant $assistant): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => ['required', 'integer', 'exists:conversations,id'],
        ]);

        if (! $this->userIsConversationParticipant($validated['conversation_id'], $request->user()?->id)) {
            return $this->unavailable('You are not part of this conversation.', 403);
        }

        if (! config('services.openrouter.api_key')) {
            return $this->unavailable('AI dispatcher is not configured on the server.');
        }

        $aiDispatcherId = User::query()->where('username', 'ai_dispatcher')->value('id');
        if (! $aiDispatcherId) {
            return $this->unavailable('AI dispatcher is not configured.');
        }

        $conversation = Conversation::query()->with(['messages', 'freightLoad.stops', 'freightLoad.consignee', 'freightLoad.company'])->findOrFail($validated['conversation_id']);

        $load = $conversation->freightLoad;
        $origin = $load?->stops->firstWhere('type', 'pickup')?->city;
        $destination = $load?->stops->firstWhere('type', 'delivery')?->city;

        $systemPrompt = 'You are the AI Dispatcher for a freight logistics platform, chatting with a dispatcher, driver, or customer about one specific load. '
            .'Answer questions using ONLY the load record given to you below — it is re-fetched from the database right before every reply you give, so it is always the current, authoritative state, even for fields you or the user discussed earlier in this conversation. '
            .'If something you said earlier in this thread conflicts with the record below (for example you previously said a field was unavailable but it now appears below), the record below is correct — quietly use it and answer normally, do not repeat the earlier claim or say the record changed. '
            .'If a field is genuinely missing or blank in the record below right now, say you don\'t have it instead of guessing. '
            .'Help draft short updates when asked. Keep replies concise (2-3 sentences) and professional, and stay in character as the dispatcher for this load only. '
            .'You do not have live GPS access. If asked about nearby fuel stations, rest stops, tolls, parking, or other amenities and the user has not told you which city or area they currently mean, ask them which city or area first instead of refusing. '
            .'Once a city or area is known (from the load\'s route or from what the user tells you), you may share a plain Google Maps search link in the form https://www.google.com/maps/search/?api=1&query=<url-encoded search terms> (e.g. query=fuel+stations+near+Stuttgart) so they can look it up themselves — never invent specific business names, addresses, or phone numbers you cannot verify. '
            .'When a link is genuinely useful, include the full https:// URL as plain text so it can be rendered as a clickable link.'
            .($load ? ' Load record: '.$this->loadFacts($load, $origin, $destination) : ' No load record is linked to this conversation.');

        $history = $conversation->messages
            ->sortBy('sent_at')
            ->map(fn (Message $message) => [
                'role' => $message->sender_user_id === $aiDispatcherId ? 'assistant' : 'user',
                'content' => $message->body,
            ])
            ->values()
            ->all();

        try {
            $reply = $assistant->reply($systemPrompt, $history);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception->getMessage());
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $aiDispatcherId,
            'body' => $reply,
            'sent_at' => now(),
        ]);
        $conversation->update(['last_message_at' => $message->sent_at]);
        $conversation->participants()->syncWithoutDetaching([$aiDispatcherId]);
        $message->load('sender');

        return response()->json([
            'message' => 'Reply generated.',
            'data' => (new EntityResource($message))->resolve($request),
            'meta' => [],
            'errors' => [],
        ], 201);
    }

    private function loadFacts(Load $load, ?string $origin, ?string $destination): string
    {
        $consignee = $load->consignee;

        return collect([
            'Title' => $load->title,
            'Status' => $load->status,
            'Booking reference' => $load->booking_reference,
            'Department' => $load->department,
            'Subdepartment' => $load->subdepartment,
            'Freight mode' => $load->freight_mode ?: $load->transport_type,
            'Cargo type' => $load->cargo_type,
            'Goods type' => $load->goods_type,
            'Weight' => $load->weight_kg ? "{$load->weight_kg} kg" : null,
            'Quantity / measure' => $load->quantity_measure,
            'Volume' => $load->volume_m3 ? "{$load->volume_m3} m3" : null,
            'TEU' => $load->teu,
            'Container types' => $load->container_types,
            'Container number' => $load->container_number,
            'Origin' => $origin,
            'Destination' => $destination,
            'ETD' => optional($load->etd_at)->toDateString(),
            'ATD' => optional($load->atd_at)->toDateString(),
            'Carrier / company' => $load->company?->name,
            'Shipper name' => $load->shipper_name,
            'Consignee' => $consignee?->company_name ?: $consignee?->name,
            'Mediator' => $load->mediator,
            'Incoterms' => $load->incoterms,
            'Insurance' => $load->insurance,
            'Price + insurance' => $load->price_insurance,
            'Budget' => $load->budget ? "{$load->currency} {$load->budget}" : null,
            'Profit & loss' => $load->profit_loss,
        ])
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, $label) => "{$label}: {$value}")
            ->implode('; ');
    }

    private function unavailable(string $message, int $status = 503): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => null, 'meta' => [], 'errors' => []], $status);
    }
}
