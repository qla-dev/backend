<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ScopesConversationAccess;
use App\Http\Controllers\Controller;
use App\Http\Resources\EntityResource;
use App\Models\Conversation;
use App\Models\Load;
use App\Models\Message;
use App\Models\User;
use App\Services\LenaLoadQuestionnaire;
use App\Services\OpenRouterDispatchAssistant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class DispatchChatController extends Controller
{
    use ScopesConversationAccess;

    public function store(Request $request, OpenRouterDispatchAssistant $assistant, LenaLoadQuestionnaire $questionnaire): JsonResponse
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

        $conversation = Conversation::query()->with(['messages', 'freightLoad.stops', 'freightLoad.consignee', 'freightLoad.company', 'freightLoad.shipment.events'])->findOrFail($validated['conversation_id']);

        $load = $conversation->freightLoad;
        $userMessages = $conversation->messages
            ->where('sender_user_id', '!=', $aiDispatcherId)
            ->sortByDesc('sent_at')
            ->values();
        $latestUserMessage = $userMessages->first()?->body;
        $guidedAction = $this->guidedAction($latestUserMessage);
        $wasCanvasEnabled = (bool) $conversation->canvas;
        $detectedLoadCreationRequest = ! $load
            && ! $wasCanvasEnabled
            && ! $guidedAction
            && $this->asksToOpenLoadCanvas($latestUserMessage);
        $shouldGenerateTitle = ! $load
            && $userMessages->count() === 1
            && in_array(trim((string) $conversation->subject), ['', 'AI Dispatch — General'], true);
        $matchedGeneralLoad = $load ? null : $this->findVisibleLoadByBookingReference($latestUserMessage, $request->user());

        // General LenaAI chats are not permanently attached to a load. Keep the most recently
        // resolved booking reference as conversational context for follow-ups such as "show the
        // details again", while never falling back to an old load after a new invalid reference.
        if (! $load
            && ! $matchedGeneralLoad
            && ! $this->mentionsBookingReference($latestUserMessage)
            && ! $wasCanvasEnabled
            && ! $detectedLoadCreationRequest
            && ! in_array($guidedAction, ['add', 'start_add_yes'], true)) {
            foreach ($userMessages->skip(1) as $earlierUserMessage) {
                $matchedGeneralLoad = $this->findVisibleLoadByBookingReference($earlierUserMessage->body, $request->user());
                if ($matchedGeneralLoad) {
                    break;
                }
            }
        }
        $contextLoad = $load ?? $matchedGeneralLoad;
        $requestedLoadCanvas = in_array($guidedAction, ['add', 'start_add_yes'], true);
        $canvasBlockedByExistingLoad = $requestedLoadCanvas && $load;
        $canvasEnabled = $wasCanvasEnabled;
        if ($canvasBlockedByExistingLoad || $guidedAction === 'continue_add_no') {
            $canvasEnabled = false;
        } elseif ($requestedLoadCanvas) {
            $canvasEnabled = true;
        }
        if ($canvasEnabled !== (bool) $conversation->canvas) {
            $conversation->update(['canvas' => $canvasEnabled]);
        }
        $loadDraft = $this->latestLoadDraft($conversation->messages);
        $nextLoadStep = $canvasEnabled ? $questionnaire->nextStep($loadDraft, $conversation->messages, (int) $aiDispatcherId) : null;
        $loadWasAlreadyReady = $questionnaire->hasCompleteReadyMarker($conversation->messages);
        $questionnaireTurn = $canvasEnabled && ! in_array($guidedAction, [
            'add', 'start_add_yes', 'upload_yes', 'tracking', 'booking', 'hs', 'free', 'continue_add_no',
        ], true);
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
            .'Determine the language of the most recent user message and write your ENTIRE reply in that language. Never mix languages inside a reply: do not insert Bosnian menu names into an English answer or English terms into a Bosnian answer. Translate ordinary feature and navigation names naturally; only proper names such as LenaAI, Freightbook.ai, and literal load reference values stay unchanged. If the latest message is only a reference number or says that a questionnaire step will be answered later, continue in the language already used by the user in this conversation. Write plain text only. Do not use Markdown, asterisks, Markdown headings, or Markdown emphasis. If a list is necessary, use short numbered lines without Markdown symbols. Never use em dashes or en dashes. Use commas, periods, parentheses, or a normal hyphen instead. '
            .'Bosnian freight terminology is strict: translate the logistics noun "load" as "teret". Never call a load "opterećenje" in Bosnian. Use the correct grammatical form of "teret" for the sentence. '
            .($canvasEnabled
                ? ' The conversation load-post canvas is active and remains active until the user selects the guided continue_add_no action. Help the user prepare a new load posting by collecting only facts they provide. Attached-file and message extraction results appear in the user message context and are authoritative for this draft; the canvas panel next to this chat already displays and updates those fields live. Because the user can already see the fields update, do not restate all field values in prose. The server controls a complete ordered questionnaire matching the load scan fields; never declare the load ready based only on title, cargo, weight, pickup, and delivery. Ask exactly one server-supplied missing step at a time. If the latest user message changes or supplies draft data, briefly confirm it and ask that next step. If it instead asks about another LenaAI capability or about Freightbook.ai, answer that request without discarding or changing modes, then write exactly [[LENA_FOLLOWUP]] on its own line, followed by a localized equivalent of "Your load is still in the data collection phase. Would you like to continue?", followed by [[LENA_OPTIONS:continue_add_yes,continue_add_no]] on its own line. In Bosnian, that follow-up sentence must be exactly "Vaš teret je još uvijek u fazi prikupljanja podataka. Želite li nastaviti?" In German, use "Ihre Ladung befindet sich noch in der Datenerfassungsphase. Möchten Sie fortfahren?" Never invent values.'
                : ' The load-post canvas is currently off. Never turn it on merely because the user types a load-creation request. The explicit Add a new load action can open it.')
            .($canvasEnabled && $nextLoadStep
                ? ' The next incomplete questionnaire step is "'.$nextLoadStep['key'].'": ask for '.$nextLoadStep['description'].'. After the natural-language question, end the reply with exactly [[LENA_STEP:'.$nextLoadStep['key'].']] on its own line. Do not ask any later step yet and do not emit LOAD_READY_TO_POST.'
                : ($canvasEnabled
                    ? ' Every questionnaire step is complete. Do not ask another load-field question. The application will show the ready-to-post card.'
                    : ''))
            .($detectedLoadCreationRequest
                ? ' The latest free-text message appears to request creation or posting of a load. Ask, in the user\'s language, whether they want to start creating the load, and end the reply with [[LENA_OPTIONS:start_add_yes,start_add_no]]. In Bosnian, ask exactly "Želite li da počnemo kreiranje tereta?" Do not say the builder or canvas is already open.'
                : '')
            .($canvasBlockedByExistingLoad
                ? ' The user asked to post a new load while an existing load is already in context. Do not open the new-load canvas and do not suggest creating a duplicate. Tell them plainly, in their language, that this load already exists and is already in status '.$statusPlain.'.'
                : '')
            .'Never discuss whether you have GPS access and never answer a location question with a generic GPS limitation. For questions about where a load is now, use the latest shipment coordinates or tracking event in the authoritative load record. If no current coordinate exists, state the latest known route point or pickup location without presenting it as a live position. '
            .'If asked about nearby fuel stations, rest stops, tolls, parking, or other amenities and the user has not told you which city or area they currently mean, ask them which city or area first instead of refusing. '
            .'Once a city or area is known (from a load\'s route or from what the user tells you), you may share a plain Google Maps search link in the form https://www.google.com/maps/search/?api=1&query=<url-encoded search terms> (e.g. query=fuel+stations+near+Stuttgart) so they can look it up themselves. Never invent specific business names, addresses, or phone numbers you cannot verify. '
            .'When a link is genuinely useful, include the full https:// URL as plain text so it can be rendered as a clickable link. '
            .'Keep replies concise and professional. Do not write longer replies as one solid block. When a reply contains more than two sentences or covers multiple ideas, organize it into short paragraphs separated by a blank line. When a current load record is available and the user asks where the load is now or for its current or latest location, first answer naturally from the latest available record and then end the reply with a new line containing exactly [[LOAD_MAP]]. When the user asks specifically about pickup, destination, route endpoints, or addresses, first answer naturally and then end the reply with a new line containing exactly [[LOAD_LOCATION]]. When the user asks for the load status, first answer naturally and then end the reply with a new line containing exactly [[LOAD_STATUS]]. When the user asks for a broader route overview, route stops, load details, or a structured load summary, first write a useful introductory sentence in the user\'s language, then end the reply with a new line containing exactly [[LOAD_DETAILS]]. The application converts these hidden signals into live data cards; never mention the signals or write HTML yourself. '
            .'Whenever you emit or cause the application to show a booking action, always write a complete, natural sentence first in the user\'s language explaining that the direct booking action is available below. The action must never appear without that preceding message.'
            .($guidedAction
                ? ' The user selected the guided LenaAI action "'.$guidedAction.'". Follow it immediately, in the user\'s language. For add or start_add_yes, ask exactly whether they have a document or file to upload and end your reply with [[LENA_OPTIONS:upload_yes,upload_no]]. For start_add_no, acknowledge briefly and keep the builder off. For upload_yes, briefly tell them to attach the file now and say you will extract the available load data before asking only the remaining fields. For upload_no, begin with the server-supplied next incomplete questionnaire step, not a hard-coded pickup question. For continue_add_yes, resume by asking the same server-supplied next incomplete step; never skip it. For continue_add_no, acknowledge that load creation has been paused and that the collected draft remains available in the conversation. For tracking or booking, ask for the booking reference and then use the current database lookup. For hs, act as an experienced HS classification specialist: confidently ask for the product description, material, intended use, and country context, then provide the most likely HS code with a concise rationale. Never refuse to help or say that you cannot classify the product. If material details are missing or more than one code is plausible, state the assumptions, give the best-fit code first, optionally list close alternatives, and label the confidence level so uncertainty is not hidden. For free, invite the user to ask freely about Freightbook.ai features and workflows. Do not expose or explain the guided action marker.'
                : '')
            .($shouldGenerateTitle
                ? ' This is the first user message in a new general LenaAI chat. Start your reply with one line in the exact form [[CHAT_TITLE:title]], where title is a concise, meaningful 3 to 7 word chat title in the user\'s language based on their request. Do not use quotation marks, brackets, em dashes, or en dashes inside the title. The application saves this hidden title; never discuss it.'
                : '')
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
                            ? ' This load is currently posted and open for direct booking. Only if the latest user message clearly asks to book, take, or reserve it, explain that booking is available below and end the reply with a new line containing exactly [[OFFER_BOOKING]]. For every other question, including details, price, status, route, and location questions, do not emit OFFER_BOOKING and do not offer a reservation action.'
                            : ' This load is currently '.$statusPlain.' and is not open for a new booking. State that plainly and do not emit any OFFER_BOOKING signal.')
                    : ' You are not currently scoped to a specific load. You can help search the actual load database by booking reference; the application performs that lookup from the reference in the user\'s latest message. If the user wants to find, book, take, or reserve a load but has not supplied its booking reference, ask for the booking reference first. Do not send them to browse the marketplace instead. If the conversation indicates they just supplied a reference and no matching visible load was found, clearly say that no load was found for that reference and ask them to check it. '
                        .'The app also has a freight marketplace for browsing available loads, a section for tracking the user\'s own loads with shipment details, a live map, return-route suggestions, invoices and reports, a Messages inbox, fleet management for companies, and analytics. Answer questions about how the platform works and freight logistics generally. If earlier turns described you as limited to one load, ignore that limitation in this general conversation.'));

        $history = $conversation->messages
            ->sortBy('sent_at')
            ->map(function (Message $message) use ($aiDispatcherId): array {
                $content = $this->guidedAction($message->body)
                    ? '[User selected guided LenaAI action: '.$this->guidedAction($message->body).']'
                    : $message->body;
                if (preg_match('/\[\[LENA_SKIP:([a-zA-Z]+)\]\]/', (string) $content, $skipMatch) === 1) {
                    $content = '[User chose to answer the questionnaire step "'.$skipMatch[1].'" later. Continue with the next server-supplied step.]';
                }

                return [
                    'role' => $message->sender_user_id === $aiDispatcherId ? 'assistant' : 'user',
                    'content' => trim((string) preg_replace(
                    '/\[\[(?:OFFER_BOOKING(?::\d+)?|LOAD_DETAILS(?::\d+)?|LOAD_LOCATION(?::\d+)?|LOAD_MAP(?::\d+)?|LOAD_STATUS(?::\d+)?|CHAT_TITLE:[^\]\r\n]+)\]\]/u',
                    '',
                        $content
                    )).$this->attachmentContext($message),
                ];
            })
            ->values()
            ->all();

        try {
            $reply = $assistant->reply($systemPrompt, $history);
        } catch (RuntimeException $exception) {
            return $this->unavailable($exception->getMessage());
        }

        $followUpReply = null;
        if (str_contains($reply, '[[LENA_FOLLOWUP]]')) {
            [$reply, $followUpReply] = array_pad(explode('[[LENA_FOLLOWUP]]', $reply, 2), 2, null);
            $reply = trim($reply);
            $followUpReply = trim((string) $followUpReply);
            if (filled($followUpReply) && ! str_contains($followUpReply, '[[LENA_OPTIONS:')) {
                $followUpReply .= "\n[[LENA_OPTIONS:continue_add_yes,continue_add_no]]";
            }
        }
        $reply = trim((string) preg_replace('/\[\[(?:LENA_STEP:[a-zA-Z]+|LOAD_READY_TO_POST(?::complete)?)\]\]/', '', $reply));
        if ($followUpReply !== null) {
            $followUpReply = trim((string) preg_replace('/\[\[(?:LENA_STEP:[a-zA-Z]+|LOAD_READY_TO_POST(?::complete)?)\]\]/', '', $followUpReply));
        }

        $generatedTitle = null;
        if ($shouldGenerateTitle && preg_match('/\[\[CHAT_TITLE:([^\]\r\n]+)\]\]/u', $reply, $titleMatch) === 1) {
            $generatedTitle = trim((string) preg_replace('/\s+/u', ' ', str_replace(['—', '–'], '-', $titleMatch[1])), " \t\n\r\0\x0B\"'");
            $generatedTitle = Str::limit($generatedTitle, 70, '');
        }
        if ($shouldGenerateTitle && blank($generatedTitle)) {
            $generatedTitle = $this->fallbackConversationTitle($latestUserMessage);
        }

        $previousUserMessage = $userMessages->get(1)?->body;
        $askedToBookLoad = $this->asksToBookLoad($latestUserMessage)
            || ($this->confirmsPreviousAction($latestUserMessage) && $this->asksToBookLoad($previousUserMessage));
        $attachedLoadOfferedBooking = $load && $askedToBookLoad && $this->isOpenForDirectBooking($load);
        $matchedGeneralLoadOfferedBooking = $matchedGeneralLoad && $askedToBookLoad && $this->isOpenForDirectBooking($matchedGeneralLoad);
        $attachedLoadDetails = $contextLoad && str_contains($reply, '[[LOAD_DETAILS]]');
        $attachedLoadStatus = $contextLoad && (
            str_contains($reply, '[[LOAD_STATUS]]')
            || $this->asksAboutLoadStatus($latestUserMessage)
        );
        $attachedLoadMap = $contextLoad && (
            str_contains($reply, '[[LOAD_MAP]]')
            || $this->asksWhereLoadIsNow($latestUserMessage)
        );
        $attachedLoadLocation = $contextLoad && ! $attachedLoadMap && (
            str_contains($reply, '[[LOAD_LOCATION]]')
            || $this->asksAboutLoadLocation($latestUserMessage)
        );
        $reply = str_replace(['—', '–'], '-', $reply);
        // LenaAI terminology guard: in Bosnian logistics, a load is always "teret",
        // never the literal and contextually incorrect translation "opterećenje".
        $reply = str_replace(
            ['Opterećenjem', 'Opterećenju', 'Opterećenja', 'Opterećenje', 'opterećenjem', 'opterećenju', 'opterećenja', 'opterećenje', 'Opterecenjem', 'Opterecenju', 'Opterecenja', 'Opterecenje', 'opterecenjem', 'opterecenju', 'opterecenja', 'opterecenje'],
            ['Teretom', 'Teretu', 'Tereta', 'Teret', 'teretom', 'teretu', 'tereta', 'teret', 'Teretom', 'Teretu', 'Tereta', 'Teret', 'teretom', 'teretu', 'tereta', 'teret'],
            $reply
        );
        $reply = trim((string) preg_replace('/\[\[(?:OFFER_BOOKING(?::\d+)?|LOAD_DETAILS(?::\d+)?|LOAD_LOCATION(?::\d+)?|LOAD_MAP(?::\d+)?|LOAD_STATUS(?::\d+)?|CHAT_TITLE:[^\]\r\n]+)\]\]/u', '', $reply));
        if (in_array($guidedAction, ['add', 'start_add_yes'], true) && ! str_contains($reply, '[[LENA_OPTIONS:')) {
            $reply .= "\n[[LENA_OPTIONS:upload_yes,upload_no]]";
        }
        if ($detectedLoadCreationRequest && ! str_contains($reply, '[[LENA_OPTIONS:')) {
            $reply .= "\n[[LENA_OPTIONS:start_add_yes,start_add_no]]";
        }
        $hasTextReply = filled($reply);
        if ($hasTextReply && $attachedLoadDetails) {
            $reply .= "\n[[LOAD_DETAILS:{$contextLoad->id}]]";
        }
        if ($hasTextReply && $attachedLoadLocation) {
            $reply .= "\n[[LOAD_LOCATION:{$contextLoad->id}]]";
        }
        if ($hasTextReply && $attachedLoadMap) {
            $reply .= "\n[[LOAD_MAP:{$contextLoad->id}]]";
        }
        if ($hasTextReply && $attachedLoadStatus) {
            $reply .= "\n[[LOAD_STATUS:{$contextLoad->id}]]";
        }
        if ($hasTextReply && $matchedGeneralLoadOfferedBooking) {
            $reply .= "\n[[OFFER_BOOKING:{$matchedGeneralLoad->id}]]";
        } elseif ($hasTextReply && $attachedLoadOfferedBooking) {
            $reply .= "\n[[OFFER_BOOKING]]";
        }
        if ($hasTextReply && $questionnaireTurn && blank($followUpReply)) {
            if ($nextLoadStep) {
                $reply .= "\n[[LENA_STEP:{$nextLoadStep['key']}]]";
            } elseif (! $loadWasAlreadyReady) {
                $reply .= "\n[[LOAD_READY_TO_POST:complete]]";
            }
        }

        $message = Message::query()->create([
            'conversation_id' => $conversation->id,
            'sender_user_id' => $aiDispatcherId,
            'body' => $reply,
            'sent_at' => now(),
        ]);
        if (filled($followUpReply)) {
            $message = Message::query()->create([
                'conversation_id' => $conversation->id,
                'sender_user_id' => $aiDispatcherId,
                'body' => $followUpReply,
                'sent_at' => now()->addMillisecond(),
            ]);
        }
        $conversationUpdate = ['last_message_at' => $message->sent_at];
        if (filled($generatedTitle)) {
            $conversationUpdate['subject'] = 'AI Dispatch — '.$generatedTitle;
        }
        $conversation->update($conversationUpdate);
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
        $shipment = $load->shipment;
        $latestTrackingEvent = $shipment?->events
            ?->first(fn ($event) => filled($event->location) || (filled($event->latitude) && filled($event->longitude)));

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
            'Latest known location' => $latestTrackingEvent?->location,
            'Latest known coordinates' => filled($latestTrackingEvent?->latitude) && filled($latestTrackingEvent?->longitude)
                ? "{$latestTrackingEvent->latitude}, {$latestTrackingEvent->longitude}"
                : (filled($shipment?->current_latitude) && filled($shipment?->current_longitude)
                    ? "{$shipment->current_latitude}, {$shipment->current_longitude}"
                    : null),
            'Latest location recorded at' => optional($latestTrackingEvent?->occurred_at)->toIso8601String(),
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
            ? Load::query()->with(['stops', 'consignee', 'company', 'shipment.events'])->find($match->id)
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

    private function asksAboutLoadLocation(?string $message): bool
    {
        if (blank($message)) {
            return false;
        }

        return preg_match(
            '/\b(destination|origin|pickup|delivery|location|address|where|route|destinacij\pL*|odredišt\pL*|polazišt\pL*|lokacij\pL*|adres\pL*|preuzimanj\pL*|dostav\pL*|odakle|dokle|gdje|kuda|ziel\pL*|startort\pL*|standort\pL*|adresse\pL*|abholung\pL*|lieferung\pL*|route|wohin|woher|wo)\b/iu',
            $message
        ) === 1;
    }

    private function asksWhereLoadIsNow(?string $message): bool
    {
        if (blank($message)) {
            return false;
        }

        return preg_match(
            '/\b(where\s+(?:is|s)\s+(?:the\s+)?load|current\s+location|latest\s+location|whereabouts|gdje\s+je\s+teret|gde\s+je\s+teret|trenutn\pL*\s+lokacij\pL*|zadnj\pL*\s+lokacij\pL*|wo\s+ist\s+(?:die\s+)?ladung|aktuell\pL*\s+standort|letzt\pL*\s+standort)\b/iu',
            $message
        ) === 1;
    }

    private function asksAboutLoadStatus(?string $message): bool
    {
        if (blank($message)) {
            return false;
        }

        $normalized = Str::lower(Str::ascii((string) $message));

        return preg_match('/\b(status|state|stage|stanje|faza|statusu|stand|zustand)\b/i', $normalized) === 1;
    }

    private function asksToBookLoad(?string $message): bool
    {
        if (blank($message)) {
            return false;
        }

        $normalized = Str::ascii(Str::lower(trim($message)));

        return preg_match(
            '/\b(book\w*|reserv\w*|take\s+(?:this|the)\s+load|accept\s+(?:this|the)\s+load|want\s+(?:this|the)\s+load|rezerv\w*|buk\w*|uz(?:mi|imam|eti)\w*|prihvat\w*|preuzimam|hocu\s+(?:ovaj\s+)?teret|zelim\s+(?:ovaj\s+)?teret|dodijel\w*\s+mi|buchen|buchung\w*|annehm\w*|diese\s+ladung\s+nehmen|ich\s+mochte\s+diese\s+ladung)\b/i',
            $normalized
        ) === 1;
    }

    private function confirmsPreviousAction(?string $message): bool
    {
        if (blank($message)) {
            return false;
        }

        $normalized = Str::ascii(Str::lower(trim($message)));

        return preg_match(
            '/^(?:pa\s+)?(?:hajde|moze|da|uradi|potvrdi|nastavi|yes|yeah|sure|go\s+ahead|do\s+it|please|okay|ok|ja|bitte|mach\s+es|weiter)\s*[.!?]*$/i',
            $normalized
        ) === 1;
    }

    private function fallbackConversationTitle(?string $message): string
    {
        $plain = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $message)));
        $plain = trim(str_replace(['—', '–'], '-', $plain), " \t\n\r\0\x0B\"'.,!?;:");

        return Str::limit(Str::words($plain, 7, ''), 70, '');
    }

    private function isOpenForDirectBooking(Load $load): bool
    {
        return $load->status === 'posted'
            && ! $load->is_negotiable
            && ! $load->assigned_driver_user_id;
    }

    private function asksToOpenLoadCanvas(?string $message): bool
    {
        $normalized = Str::lower(Str::ascii((string) $message));

        return preg_match('/\b(new\s+load|post\s+(?:a\s+)?load|publish\s+(?:a\s+)?load|create\s+(?:a\s+)?load|bulk\s+import|novi\s+teret|nov\s+teret|objav\w*\s+teret|kreir\w*\s+teret|naprav\w*\s+teret|masovni\s+uvoz|neue\s+ladung|ladung\s+(?:erstellen|veroffentlichen)|massenimport|(open|enable|show|otvori|ukljuci|prikazi|offne|aktiviere)\w*\s+(?:the\s+)?(canvas|platno|nacrt))\b/i', $normalized) === 1;
    }

    private function guidedAction(?string $message): ?string
    {
        if (! is_string($message)) {
            return null;
        }

        return preg_match('/^\[\[LENA_ACTION:(add|tracking|booking|hs|free|upload_yes|upload_no|start_add_yes|start_add_no|continue_add_yes|continue_add_no)\]\]$/', trim($message), $match) === 1
            ? $match[1]
            : null;
    }

    private function attachmentContext(Message $message): string
    {
        $attachments = collect($message->attachments ?? [])->map(function ($attachment): array {
            if (! is_array($attachment)) {
                return [];
            }

            return array_filter([
                'file' => $attachment['name'] ?? null,
                'type' => $attachment['type'] ?? null,
                'loadScan' => $attachment['loadScan'] ?? null,
                'bulkRows' => $attachment['bulkRows'] ?? null,
            ], fn ($value) => $value !== null && $value !== [] && $value !== '');
        })->filter()->values()->all();

        return $attachments === []
            ? ''
            : "\n\nAttached file extraction context:\n".json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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

    private function unavailable(string $message, int $status = 503): JsonResponse
    {
        return response()->json(['message' => $message, 'data' => null, 'meta' => [], 'errors' => []], $status);
    }
}
