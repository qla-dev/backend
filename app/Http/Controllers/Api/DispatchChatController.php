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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
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
        $userMessages = $conversation->messages
            ->where('sender_user_id', '!=', $aiDispatcherId)
            ->sortByDesc('sent_at')
            ->values();
        $latestUserMessage = $userMessages->first()?->body;
        $matchedGeneralLoad = $load ? null : $this->findVisibleLoadByBookingReference($latestUserMessage, $request->user());

        // General LenaAI chats are not permanently attached to a load. Keep the most recently
        // resolved booking reference as conversational context for follow-ups such as "show the
        // details again", while never falling back to an old load after a new invalid reference.
        if (! $load && ! $matchedGeneralLoad && ! $this->mentionsBookingReference($latestUserMessage)) {
            foreach ($userMessages->skip(1) as $earlierUserMessage) {
                $matchedGeneralLoad = $this->findVisibleLoadByBookingReference($earlierUserMessage->body, $request->user());
                if ($matchedGeneralLoad) {
                    break;
                }
            }
        }
        $contextLoad = $load ?? $matchedGeneralLoad;
        $origin = $contextLoad?->stops->firstWhere('type', 'pickup')?->city;
        $destination = $contextLoad?->stops->firstWhere('type', 'delivery')?->city;

        $statusLabels = [
            'posted' => 'posted and open for booking',
            'opened' => 'opened',
            'sent' => 'booked and in preparation',
            'in_delivery' => 'booked and in transit',
            'received' => 'received at its destination',
            'finished' => 'finished',
            'pending' => 'pending, not yet published',
            'cancelled' => 'cancelled',
        ];
        $statusPlain = $contextLoad
            ? ($contextLoad->status === 'posted' && $contextLoad->is_negotiable
                ? 'posted and accepting offers rather than direct booking'
                : ($contextLoad->status === 'posted' && $contextLoad->assigned_driver_user_id
                    ? 'already assigned and no longer open for direct booking'
                    : ($statusLabels[$contextLoad->status] ?? $contextLoad->status)))
            : null;

        $askingUser = $request->user();
        $isLoadOwner = $contextLoad && $askingUser && (
            (int) $contextLoad->assigned_driver_user_id === (int) $askingUser->id
            || (int) $contextLoad->customer_user_id === (int) $askingUser->id
            || ($contextLoad->company_id && $askingUser->companies()->where('companies.id', $contextLoad->company_id)->exists())
        );

        $systemPrompt = 'You are LenaAI, the assistant for the Freightbook.ai freight logistics platform. '
            .'Determine the language of the most recent user message and write your ENTIRE reply in that language. Never mix languages inside a reply: do not insert Bosnian menu names into an English answer or English terms into a Bosnian answer. Translate ordinary feature and navigation names naturally; only proper names such as LenaAI, Freightbook.ai, and literal load reference values stay unchanged. If the latest message is only a reference number, continue in the language already used by the user in this conversation. Never use em dashes or en dashes. Use commas, periods, parentheses, or a normal hyphen instead. '
            .'You do not have live GPS access. If asked about nearby fuel stations, rest stops, tolls, parking, or other amenities and the user has not told you which city or area they currently mean, ask them which city or area first instead of refusing. '
            .'Once a city or area is known (from a load\'s route or from what the user tells you), you may share a plain Google Maps search link in the form https://www.google.com/maps/search/?api=1&query=<url-encoded search terms> (e.g. query=fuel+stations+near+Stuttgart) so they can look it up themselves. Never invent specific business names, addresses, or phone numbers you cannot verify. '
            .'When a link is genuinely useful, include the full https:// URL as plain text so it can be rendered as a clickable link. '
            .'Keep replies concise and professional. Do not write longer replies as one solid block. When a reply contains more than two sentences or covers multiple ideas, organize it into short paragraphs separated by a blank line. When a current load record is available and the user asks for a route overview, route stops, load details, or a structured load summary, first write a useful introductory sentence in the user\'s language, then end the reply with a new line containing exactly [[LOAD_DETAILS]]. The application converts that hidden signal into a live data card; never mention the signal or write HTML yourself. '
            .'Whenever you emit or cause the application to show a booking action, always write a complete, natural sentence first in the user\'s language explaining that the direct booking action is available below. The action must never appear without that preceding message.'
            .($load
                ? ' You are chatting about one specific load. Answer questions using ONLY the load record given below. It is re-fetched from the database right before every reply you give, so it is always the current, authoritative state, even for fields you or the user discussed earlier in this conversation. '
                    .'If something you said earlier in this thread conflicts with the record below (for example you previously said a field was unavailable but it now appears below), the record below is correct. Quietly use it and answer normally, do not repeat the earlier claim or say the record changed. '
                    .'If a field is genuinely missing or blank in the record below right now, say you don\'t have it instead of guessing. Help draft short updates when asked, and stay in character as the dispatcher for this load only. '
                    .'Load record: '.$this->loadFacts($load, $origin, $destination)
                    .($this->isOpenForDirectBooking($load)
                        ? ' This load is posted and open to be booked. If, and only if, the user clearly says they want to book, take, or reserve this specific load, end your reply with a new line containing exactly the text [[OFFER_BOOKING]] and nothing else on that line (it is a hidden signal for the app, never mention it or explain it to the user). Do not include it for vague interest, questions about the load, or anything short of a clear booking request.'
                        : ' This load is currently '.$statusPlain.'. It is NOT open for new booking. If the user asks why they cannot book it, or asks to book/take/reserve it, never suggest contacting another team, hub, or outside channel (no such channel exists). Just tell them plainly, in one short sentence, that it is already '.$statusPlain.'.'
                            .($isLoadOwner ? ' Important: the person you are chatting with is already the driver or company assigned to this exact load, so make that clear in your answer. They are not being blocked from booking a load that belongs to someone else, they already have this one; there is nothing further for them to book.' : ''))
                : ($matchedGeneralLoad
                    ? ' This is a general LenaAI conversation, and the database search found the load whose booking reference the user supplied. Use only the current authoritative load record below when discussing it. '
                        .'Load record: '.$this->loadFacts($matchedGeneralLoad, $origin, $destination).'. '
                        .($this->isOpenForDirectBooking($matchedGeneralLoad)
                            ? ' This load is currently posted and open for direct booking. Tell the user it was found and is available; the application will add the booking action automatically. Never write or explain an OFFER_BOOKING signal yourself.'
                            : ' This load is currently '.$statusPlain.' and is not open for a new booking. State that plainly and do not emit any OFFER_BOOKING signal.')
                    : ' You are not currently scoped to a specific load. You can help search the actual load database by booking reference; the application performs that lookup from the reference in the user\'s latest message. If the user wants to find, book, take, or reserve a load but has not supplied its booking reference, ask for the booking reference first. Do not send them to browse the marketplace instead. If the conversation indicates they just supplied a reference and no matching visible load was found, clearly say that no load was found for that reference and ask them to check it. '
                        .'The app also has a freight marketplace for browsing available loads, a section for tracking the user\'s own loads with shipment details, a live map, return-route suggestions, invoices and reports, a Messages inbox, fleet management for companies, and analytics. Answer questions about how the platform works and freight logistics generally. If earlier turns described you as limited to one load, ignore that limitation in this general conversation.'));

        $history = $conversation->messages
            ->sortBy('sent_at')
            ->map(fn (Message $message) => [
                'role' => $message->sender_user_id === $aiDispatcherId ? 'assistant' : 'user',
                'content' => trim((string) preg_replace(
                    '/\[\[(?:OFFER_BOOKING(?::\d+)?|LOAD_DETAILS(?::\d+)?)\]\]/',
                    '',
                    $message->body
                )),
            ])
            ->values()
            ->all();

        try {
            $reply = $assistant->reply($systemPrompt, $history);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception->getMessage());
        }

        $attachedLoadOfferedBooking = $load && $this->isOpenForDirectBooking($load) && str_contains($reply, '[[OFFER_BOOKING]]');
        $attachedLoadDetails = $contextLoad && str_contains($reply, '[[LOAD_DETAILS]]');
        $reply = str_replace(['—', '–'], '-', $reply);
        $reply = trim((string) preg_replace('/\[\[(?:OFFER_BOOKING(?::\d+)?|LOAD_DETAILS(?::\d+)?)\]\]/', '', $reply));
        $hasTextReply = filled($reply);
        if ($hasTextReply && $attachedLoadDetails) {
            $reply .= "\n[[LOAD_DETAILS:{$contextLoad->id}]]";
        }
        if ($hasTextReply && $matchedGeneralLoad && $this->isOpenForDirectBooking($matchedGeneralLoad)) {
            $reply .= "\n[[OFFER_BOOKING:{$matchedGeneralLoad->id}]]";
        } elseif ($hasTextReply && $attachedLoadOfferedBooking) {
            $reply .= "\n[[OFFER_BOOKING]]";
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

    private function findVisibleLoadByBookingReference(?string $message, ?User $user): ?Load
    {
        if (blank($message)) {
            return null;
        }

        $normalizedMessage = $this->normalizeReference($message);
        $lowerMessage = Str::lower($message);
        if (strlen($normalizedMessage) < 3) {
            return null;
        }

        $query = Load::query()
            ->whereNotNull('booking_reference')
            ->where('booking_reference', '!=', '');

        $this->scopeLoadsVisibleToUser($query, $user);

        $match = $query
            ->get(['id', 'booking_reference'])
            ->filter(function (Load $candidate) use ($lowerMessage, $normalizedMessage): bool {
                $literalReference = Str::lower(trim((string) $candidate->booking_reference));
                $reference = $this->normalizeReference((string) $candidate->booking_reference);
                $literalMatch = $literalReference !== '' && preg_match(
                    '/(?<![\pL\pN])'.preg_quote($literalReference, '/').'(?![\pL\pN])/u',
                    $lowerMessage
                ) === 1;

                return strlen($reference) >= 3 && (
                    $literalMatch
                    || $normalizedMessage === $reference
                    || (strlen($reference) >= 5 && str_contains($normalizedMessage, $reference))
                );
            })
            ->sortByDesc(fn (Load $candidate) => strlen($this->normalizeReference((string) $candidate->booking_reference)))
            ->first();

        return $match
            ? Load::query()->with(['stops', 'consignee', 'company'])->find($match->id)
            : null;
    }

    private function scopeLoadsVisibleToUser(Builder $query, ?User $user): void
    {
        $role = $user?->role?->name;
        if ($role === 'superadmin') {
            return;
        }

        $query->where(function (Builder $visible) use ($user, $role): void {
            $visible->where('status', 'posted');
            if (! $user) {
                return;
            }

            if ($role === 'user') {
                $visible->orWhere('customer_user_id', $user->id);
            } elseif ($role === 'driver') {
                $visible->orWhere('assigned_driver_user_id', $user->id);
            } elseif (in_array($role, ['company', 'finance'], true)) {
                $companyIds = $user->companies()->pluck('companies.id');
                $visible->orWhere('customer_user_id', $user->id)->orWhereIn('company_id', $companyIds);
            }
        });
    }

    private function normalizeReference(string $value): string
    {
        return preg_replace('/[^\pL\pN]+/u', '', Str::lower($value)) ?? '';
    }

    private function mentionsBookingReference(?string $message): bool
    {
        if (blank($message)) {
            return false;
        }

        return preg_match(
            '/(?<![\pL\pN])(?=[\pL\pN-]{3,}(?![\pL\pN-]))(?=[\pL\pN-]*\pL)(?=[\pL\pN-]*\pN)[\pL\pN]+(?:-[\pL\pN]+)*(?![\pL\pN-])|(?<!\d)\d{4,}(?!\d)/u',
            $message
        ) === 1;
    }

    private function isOpenForDirectBooking(Load $load): bool
    {
        return $load->status === 'posted'
            && ! $load->is_negotiable
            && ! $load->assigned_driver_user_id;
    }

    private function unavailable(string $message, int $status = 503): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => null, 'meta' => [], 'errors' => []], $status);
    }
}
