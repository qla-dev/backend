<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class OpenRouterLoadScanner
{
    private const MAX_ATTEMPTS = 3;

    private const BODY_TYPES = ['Curtain', 'Box', 'Reefer', 'Mega', 'Tautliner', 'Flatbed'];

    private const TRANSPORT_TYPES = ['road', 'air', 'sea'];

    private const LOADING_EQUIPMENT_TYPES = ['Vehicle with ramp', 'Vehicle without ramp', 'Forklift: Yes', 'Forklift: No', 'Other loading/unloading equipment', 'Not specified'];

    private const PRICE_TERMS = ['fixed', 'negotiable'];

    public function __construct(
        private readonly RelativeLoadDateResolver $relativeDates,
        private readonly HsCodeSearchService $hsCodes,
        private readonly AiCallLogger $logger,
    ) {}

    public function scan(array $images, array $current = [], ?int $conversationId = null): array
    {
        $userPrompt = 'Read the consignee/customer company name, tax or VAT number, city and country code, a short title summarizing the load, the road/air/sea transport type, the cargo type (e.g. Pallets, Machinery, Electronics), the goods type/description, '
            .'the weight in kilograms, the pallet/unit count, the required trailer body type if stated, '
            .'dimensions and volume, required vehicle type, loading/unloading equipment, road or air handling characteristics, special requirements, transport mode, delivery proof requirement, whether tracking is required, '
            .'the pickup city, country code, street address, latitude, longitude and date (plus a date-range end and time window if given), the delivery city, country code, street address, latitude, longitude and date (plus a date-range end and time window if given), '
            .'the currency, the agreed price or rate, whether the price is fixed or open to offers, the declared cargo value and its currency, '
            .'Incoterm, deferred payment days, temperature range, ADR, tail-lift, toll-road, ferry, CMR, pallet-exchange, customs and urgency requirements, contact name/phone/mobile/fax/email, '
            .'the booking or reference number, any other short notes that do not belong in a dedicated field, '
            .'and any distinct fact that should be tracked as its own separate custom item rather than folded into notes.'
            .$this->currentDraftContext($current);

        $content = [
            ['type' => 'text', 'text' => $userPrompt],
            ...array_map(fn (array $file) => ($file['mimeType'] ?? '') === 'application/pdf'
                ? [
                    'type' => 'file',
                    'file' => [
                        'filename' => $file['filename'] ?? 'freight-document.pdf',
                        'file_data' => 'data:application/pdf;base64,'.$file['base64'],
                    ],
                ]
                : [
                    'type' => 'image_url',
                    'image_url' => [
                        'url' => 'data:'.($file['mimeType'] ?? 'image/jpeg').';base64,'.$file['base64'],
                    ],
                ], $images),
        ];

        $result = $this->enrichHsCodes($this->run($this->documentSystemPrompt($current), $content, 'images', 'load_scan', $conversationId, true));

        return $current === [] ? $result : $this->mergeWithCurrent($result, $current);
    }

    // The exact field(s) each questionnaire step (see LenaLoadQuestionnaire::STEPS) writes to,
    // used only to tell the scanner unambiguously which field a bare, context-free answer like
    // "50" belongs to - see the pendingStep instruction in textSystemPrompt().
    private const STEP_FIELD_HINTS = [
        'title' => 'title',
        'goodsType' => 'goodsType (and cargoType if a broader category is also stated)',
        'weight' => 'weightKg (a plain number of kilograms - never pallets)',
        'pallets' => 'pallets (a plain count of pallets/units - never weightKg)',
        'dimensions' => 'lengthM, widthM, heightM (meters) and/or volumeM3',
        'pickup' => 'pickupCity, pickupCountryCode, pickupAddress',
        'pickupDate' => 'pickupDate (and pickupTimeFrom/pickupTimeTo if a time window is given)',
        'delivery' => 'deliveryCity, deliveryCountryCode, deliveryAddress',
        'deliveryDate' => 'deliveryDate (and deliveryTimeFrom/deliveryTimeTo if a time window is given)',
        'budget' => 'budget (a plain price amount) and currency',
        'declaredValue' => 'declaredValue (a plain amount) and declaredValueCurrency',
        'temperature' => 'temperatureMin and/or temperatureMax (degrees Celsius)',
        'notes' => 'notes',
    ];

    public function scanText(string $description, array $current = [], ?int $conversationId = null, ?string $pendingStep = null): array
    {
        $serverDate = now()->toDateString();
        $serverTimezone = (string) config('app.timezone', 'UTC');
        $userPrompt = 'The shipper described the load in their own words below. Extract the consignee/customer company name, tax or VAT number, city and country code, a short title, the road/air/sea transport type, the cargo type, the goods type/description, the weight in kilograms, the pallet/unit count, '
            .'the required trailer body type if stated, the pickup city, country code, street address, latitude, longitude and date (plus a date-range end and time window if given), '
            .'the delivery city, country code, street address, latitude, longitude and date (plus a date-range end and time window if given), '
            .'dimensions, volume, vehicle, loading/unloading equipment, road or air handling characteristics, special requirements, transport mode, delivery proof requirement, whether tracking is required, '
            .'the currency, agreed price or rate, whether the price is fixed or open to offers, the declared cargo value and its currency, '
            .'Incoterm, deferred payment days, temperature range, ADR, tail-lift, toll-road, ferry, CMR, pallet-exchange, customs, urgency, contact name/phone/mobile/fax/email, '
            .'the booking or reference number, any other short notes that do not belong in a dedicated field, '
            .'and any distinct fact that should be tracked as its own separate custom item rather than folded into notes.'
            ."\n\nAuthoritative server date: {$serverDate} ({$serverTimezone}). Resolve every relative date from this date."
            .$this->currentDraftContext($current)
            .($pendingStep && isset(self::STEP_FIELD_HINTS[$pendingStep])
                ? "\n\nThe shipper's message below is specifically their answer to one single already-asked question, about exactly this field: ".self::STEP_FIELD_HINTS[$pendingStep].'. '
                    .'If the message is a bare number or short value with no other identifying context, it belongs exclusively to that field - never guess it into a different field, and never leave that field empty when the message clearly answers it. Do not let this focus change or clear any other field the current draft above already has.'
                : '')
            ."\n\nDescription:\n".$description;

        $content = [['type' => 'text', 'text' => $userPrompt]];

        $result = $this->run($this->textSystemPrompt($current), $content, 'description', 'load_scan_text', $conversationId, false);
        $result = $this->enrichHsCodes($this->relativeDates->apply($description, $result, $current));

        return $current === [] ? $result : $this->mergeWithCurrent($result, $current);
    }

    private function currentDraftContext(array $current): string
    {
        $meaningful = array_filter(
            $current,
            fn ($value) => $value !== '' && $value !== 0 && $value !== false && $value !== null && $value !== []
        );
        if ($meaningful === []) {
            return '';
        }

        return "\n\nCurrent known draft (already confirmed in earlier turns, as JSON):\n"
            .json_encode($meaningful, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ."\n\nThis draft already exists; you are updating it, not starting over. Copy every field from it into your answer unchanged, "
            .'EXCEPT the field(s) the new message actually addresses. '
            .'When the message asks to add, increase, remove, or decrease a quantity or amount (e.g. "add 10 more pallets", "add another 5", "smanji za 2"), '
            .'compute the new value from the current draft value above, do not just repeat the number mentioned. '
            .'When the message clearly sets or replaces a field (e.g. "change destination to X", "make it 30 pallets total"), use the new value. '
            .'The customFields list works the same way: keep every existing entry from the current draft above and only append a new one or edit a matching one, never drop existing entries just because the new message does not mention them. '
            .'If the new message is not about the load at all (a question, a complaint, small talk, or anything with no new load information), '
            .'return the draft above completely unchanged and leave notes and customFields exactly as they already were; never place unrelated conversational text into notes or customFields.';
    }

    // Structured extraction naturally reports only what it saw in the latest input, defaulting
    // everything else back to empty/0/false regardless of the "carry the current draft forward"
    // instruction above - the prompt alone is not reliable enough to prevent data loss across turns.
    // This deterministically restores any field the new result left empty/default when the existing
    // draft already had a real value for it, so a second document (or a follow-up answer) can never
    // silently wipe out earlier turns' answers.
    private function mergeWithCurrent(array $result, array $current): array
    {
        // These describe THIS scan, not accumulated draft data, so they must always reflect
        // what was actually just read - never backfilled from an earlier, unrelated scan.
        $ownFields = ['isDocument', 'confidence', 'warnings'];

        foreach ($current as $field => $value) {
            if (! array_key_exists($field, $result) || in_array($field, $ownFields, true)) {
                continue;
            }

            $resultIsEmpty = $field === 'title'
                ? $this->isEmptyScanValue($result[$field]) || $result[$field] === 'New load'
                : $this->isEmptyScanValue($result[$field]);

            if ($resultIsEmpty && ! $this->isEmptyScanValue($value)) {
                $result[$field] = $value;
            }
        }

        if (is_array($current['customFields'] ?? null) && $current['customFields'] !== []) {
            $existingLabels = collect($result['customFields'] ?? [])->pluck('label');
            $result['customFields'] = collect($result['customFields'] ?? [])
                ->concat(collect($current['customFields'])->reject(
                    fn ($item) => is_array($item) && $existingLabels->contains($item['label'] ?? null)
                ))
                ->take(8)
                ->values()
                ->all();
        }

        return $result;
    }

    private function isEmptyScanValue(mixed $value): bool
    {
        return $value === '' || $value === 0 || $value === 0.0 || $value === false || $value === null || $value === [];
    }

    private function documentSystemPrompt(array $current = []): string
    {
        return 'You read a freight document (a shipping order, rate confirmation, bill of lading, cargo manifest, or booking note) '
            .'to prefill a new load posting form. Do not invent values you cannot read; use an empty string, 0, or false for anything not shown, '
            .(($current !== []) ? 'unless a current draft is given below, in which case carry its existing values forward for anything this document does not address. ' : '')
            .'Identify the consignee/customer whose record should be attached to the load. Return its printed legal company name, tax/VAT/ID number, city and two-letter country code. Prefer a tax/VAT/ID number because it uniquely identifies the database record; do not confuse a contact person with the company. '
            .'Read the pickup and delivery locations as city names, and their two-letter ISO 3166-1 alpha-2 country codes. '
            .'Read the pickup date and delivery date separately as YYYY-MM-DD; if only one date is shown, use it for whichever of the two it clearly refers to and leave the other empty. '
            .'Read the cargo weight in kilograms, converting from other units if the document states them explicitly (e.g. lbs, tons). '
            .'For any identifiable goods, return hsSearchTerms as a short English catalog search phrase (product, material, processing state, intended use) for each distinct product visible in the document, separated by semicolons when there is more than one; try to identify every distinct product, not just the main or first one, so each can get its own HS code immediately, without waiting for a later message. A single phrase is fine when only one product is shown. Preserve any explicitly printed six-digit HS codes in hsCodes. '
            .'Read the pallet or unit count as a plain number when the document states a quantity (e.g. "24 pallets" -> 24). '
            .'Read the required trailer/body type only when explicitly stated or clearly implied (e.g. "cerada"/"tarpaulin"/"curtain-sider" means Curtain; "hladnjaca"/"refrigerated" means Reefer; "furgon"/"box" means Box), choosing exactly one of: Curtain, Box, Reefer, Mega, Tautliner, Flatbed - or an empty string if not stated. '
            .'Read the transport type as exactly one of road, air, sea when it is stated or clearly implied (e.g. a flight or airport reference means air, a vessel or port reference means sea); otherwise leave it an empty string. '
            .'Read the street address separately from the city when one is given. Preserve explicit pickup or delivery latitude and longitude coordinates when supplied; otherwise return null for them. If a pickup or delivery is a date range, put the start in the date field and the end in the matching "date to" field; read a stated time window into the matching "time from"/"time to" fields as HH:MM, 24-hour format. '
            .'Read loading/unloading equipment only when explicitly stated, choosing exactly one of: Vehicle with ramp, Vehicle without ramp, Forklift: Yes, Forklift: No, Other loading/unloading equipment, Not specified - or an empty string if not stated. '
            .'Read road or air handling characteristics (e.g. ADR, CMR, GDP, TIR, Lift, Express for road; Non-DG, DG, TCG, MED, VAL for air) and any special requirements only when explicitly stated. Read the transport mode (e.g. "Airport to airport") and whether proof of delivery is required only for air/sea shipments when stated. Set requiresTracking to true only when live tracking is explicitly requested. '
            .'Read whether the price is fixed or open to negotiation/offers as priceTerms (fixed or negotiable) only when the document is explicit about it; otherwise leave it an empty string. Read a declared/insured cargo value and its currency separately from the freight price when stated. '
            .'Extract dimensions in meters, volume in cubic meters, vehicle type, Incoterm, deferred payment days, temperature range in Celsius, ADR/tail-lift/toll-road/ferry/CMR/pallet-exchange/customs/urgency flags, insurance, certification, inspection-service requirements, and contact details only when explicitly present. '
            .'Read the currency from the symbol or code printed on the document and return its ISO 4217 code. '
            .'Put leftover information that has no dedicated field (e.g. special handling instructions) in notes - never repeat the pallet count, dates, or body type inside notes since those already have their own fields. '
            .'If the shipper explicitly asks for something to be tracked as its own separate item rather than lumped into notes (e.g. "add this as a new item, not as notes", "dodaj kao novi item, ne kao notes"), add one entry to customFields instead, with a short label in the language they used and its value; do not also duplicate that same fact inside notes. '
            .'Set isDocument to true only when the image really shows a freight/shipping document; otherwise set it to false and do not invent data. '
            .'Return only the fields requested in the JSON schema.';
    }

    private function textSystemPrompt(array $current = []): string
    {
        return 'You read a free-text description of a freight load, written by a shipper in plain language (any of English, Bosnian/Croatian/Serbian, or German), '
            .'to prefill a new load posting form. Do not invent values that are not stated or clearly implied; use an empty string, 0, or false for anything not mentioned, '
            .(($current !== []) ? 'unless a current draft is given below, in which case carry its existing values forward for anything this message does not address, and correctly apply incremental changes (add, increase, remove, decrease) using the current value as the base. ' : '')
            .'Identify the consignee/customer when the user names one. Return its company name, tax/VAT/ID number, city and two-letter country code; do not confuse a contact person with the company. '
            .'Read the pickup and delivery locations as city names, and their two-letter ISO 3166-1 alpha-2 country codes. '
            .'Read the pickup date and delivery date separately as YYYY-MM-DD; if only one date is mentioned, use it for whichever of the two it clearly refers to and leave the other empty. '
            .'The user may give a raw date or a relative date. Resolve danas/today/heute as the server date, sutra/tomorrow/morgen as server date plus 1 day, prekosutra/day after tomorrow/übermorgen as server date plus 2 days, and "za N dana"/"in N days"/"in N Tagen" as server date plus N days. Never infer the year from model knowledge or training data. '
            .'Read the cargo weight in kilograms, converting from other units if stated explicitly (e.g. lbs, tons). '
            .'For any identifiable goods, return hsSearchTerms as a short English catalog search phrase (product, material, processing state, intended use) for each distinct product stated by the user, separated by semicolons when there is more than one; try to identify every distinct product, not just the main or first one. A single phrase is fine when only one product is mentioned. Preserve any explicitly stated six-digit HS codes in hsCodes. '
            .'Read the pallet or unit count as a plain number when a quantity is mentioned (e.g. "24 paleta" -> 24). '
            .'Read the required trailer/body type only when explicitly stated or clearly implied (e.g. "cerada"/"tarpaulin"/"curtain-sider" means Curtain; "hladnjaca"/"refrigerated" means Reefer; "furgon"/"box" means Box), choosing exactly one of: Curtain, Box, Reefer, Mega, Tautliner, Flatbed - or an empty string if not stated. '
            .'Read the transport type as exactly one of road, air, sea when it is stated or clearly implied (e.g. a flight or airport reference means air, a vessel or port reference means sea); otherwise leave it an empty string. '
            .'Read the street address separately from the city when one is given. Preserve explicit pickup or delivery latitude and longitude coordinates when supplied; otherwise return null for them. If a pickup or delivery is a date range, put the start in the date field and the end in the matching "date to" field; read a stated time window into the matching "time from"/"time to" fields as HH:MM, 24-hour format. '
            .'Read loading/unloading equipment only when explicitly stated, choosing exactly one of: Vehicle with ramp, Vehicle without ramp, Forklift: Yes, Forklift: No, Other loading/unloading equipment, Not specified - or an empty string if not stated. '
            .'Read road or air handling characteristics (e.g. ADR, CMR, GDP, TIR, Lift, Express for road; Non-DG, DG, TCG, MED, VAL for air) and any special requirements only when explicitly stated. Read the transport mode (e.g. "Airport to airport") and whether proof of delivery is required only for air/sea shipments when stated. Set requiresTracking to true only when live tracking is explicitly requested. '
            .'Read whether the price is fixed or open to negotiation/offers as priceTerms (fixed or negotiable) only when the message is explicit about it; otherwise leave it an empty string. Read a declared/insured cargo value and its currency separately from the freight price when stated. '
            .'Extract dimensions in meters, volume in cubic meters, vehicle type, Incoterm, deferred payment days, temperature range in Celsius, ADR/tail-lift/toll-road/ferry/CMR/pallet-exchange/customs/urgency flags, insurance, certification, inspection-service requirements, and contact details only when stated. '
            .'Read the currency from the symbol or code mentioned and return its ISO 4217 code, defaulting to EUR if a price is given without a currency. '
            .'Put leftover information that has no dedicated field (e.g. special handling instructions) in notes - never repeat the pallet count, dates, or body type inside notes since those already have their own fields. '
            .'If the shipper explicitly asks for something to be tracked as its own separate item rather than lumped into notes (e.g. "add this as a new item, not as notes", "dodaj kao novi item, ne kao notes", "dodaj kao zasebnu stavku"), add one entry to customFields instead, with a short label in the language they used and its value; do not also duplicate that same fact inside notes. '
            .'Set isDocument to true whenever the text describes a freight load (even briefly); set it to false only when the text is unrelated to freight/shipping. '
            .'Return only the fields requested in the JSON schema.';
    }

    private function run(string $systemPrompt, array $content, string $errorField, string $service, ?int $conversationId, bool $hasAttachment): array
    {
        $payload = $this->requestPayload($systemPrompt, $content);
        $startedAt = microtime(true);
        $lastResponseJson = null;
        $lastHttpStatus = null;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken((string) config('services.openrouter.api_key'))
                    ->acceptJson()
                    ->timeout(90)
                    ->withHeaders([
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'Freightbook.ai Load Scanner',
                    ])
                    ->post((string) config('services.openrouter.url'), $payload);

                $lastResponseJson = $response->json();
                $lastHttpStatus = $response->status();
                $this->ensureSuccessful($response, $errorField);
                $result = json_decode($this->outputText($lastResponseJson), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($result)) {
                    throw new RuntimeException('The AI service returned an invalid scan result.');
                }

                $this->logCall($service, $payload, $lastResponseJson, $lastHttpStatus, $attempt, $conversationId, $hasAttachment, $startedAt, true, null);

                return $this->normalizeResult($result);
            } catch (ConnectionException|JsonException|RuntimeException|ValidationException $exception) {
                if ($attempt === self::MAX_ATTEMPTS) {
                    $this->logCall($service, $payload, $lastResponseJson, $lastHttpStatus, $attempt, $conversationId, $hasAttachment, $startedAt, false, $exception->getMessage());

                    if ($exception instanceof ValidationException) {
                        throw $exception;
                    }
                    throw ValidationException::withMessages([
                        $errorField => ['The AI could not read this after several attempts. Please try again.'],
                    ]);
                }

                Log::warning('Load scan AI call failed; retrying automatically.', [
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function logCall(string $service, array $payload, ?array $response, ?int $httpStatus, int $attempt, ?int $conversationId, bool $hasAttachment, float $startedAt, bool $success, ?string $error): void
    {
        $this->logger->record([
            'service' => $service,
            'conversation_id' => $conversationId,
            'model' => data_get($response, 'model', $payload['model']),
            'provider' => data_get($response, 'provider'),
            'generation_id' => data_get($response, 'id'),
            'finish_reason' => data_get($response, 'choices.0.finish_reason'),
            'temperature' => $payload['temperature'] ?? null,
            'has_attachment' => $hasAttachment,
            'is_success' => $success,
            'error_message' => $error,
            'request_payload' => AiCallLogger::redactBase64($payload),
            'response_payload' => $response,
            'prompt_tokens' => data_get($response, 'usage.prompt_tokens'),
            'completion_tokens' => data_get($response, 'usage.completion_tokens'),
            'total_tokens' => data_get($response, 'usage.total_tokens'),
            'cached_tokens' => data_get($response, 'usage.prompt_tokens_details.cached_tokens'),
            'reasoning_tokens' => data_get($response, 'usage.completion_tokens_details.reasoning_tokens'),
            'cost_usd' => data_get($response, 'usage.cost'),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'http_status' => $httpStatus,
            'attempt_count' => $attempt,
        ]);
    }

    private function requestPayload(string $systemPrompt, array $content): array
    {
        return [
            'model' => config('services.openrouter.model'),
            'temperature' => 0,
            'provider' => ['require_parameters' => true],
            'usage' => ['include' => true],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'freightbook_load_document',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $content],
            ],
        ];
    }

    private function ensureSuccessful(Response $response, string $errorField): void
    {
        if ($response->successful()) {
            return;
        }

        throw ValidationException::withMessages([
            $errorField => ['AI scanning is not available right now. Please try again.'],
        ]);
    }

    private function outputText(array $payload): string
    {
        $content = data_get($payload, 'choices.0.message.content');
        if (is_string($content) && trim($content) !== '') {
            return trim($content);
        }
        if (is_array($content)) {
            $text = collect($content)->pluck('text')->filter()->implode("\n");
            if (trim($text) !== '') {
                return trim($text);
            }
        }

        throw new RuntimeException(data_get($payload, 'error.message', 'The AI service did not return a scan result.'));
    }

    private function normalizeResult(array $result): array
    {
        $isDocument = ($result['isDocument'] ?? true) === true;

        $currency = strtoupper($this->stringValue($result['currency'] ?? ''));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $bodyType = $this->stringValue($result['bodyType'] ?? '');
        if (! in_array($bodyType, self::BODY_TYPES, true)) {
            $bodyType = '';
        }

        $transportType = strtolower($this->stringValue($result['transportType'] ?? ''));
        if (! in_array($transportType, self::TRANSPORT_TYPES, true)) {
            $transportType = '';
        }

        $loadingEquipment = $this->stringValue($result['loadingEquipment'] ?? '');
        if (! in_array($loadingEquipment, self::LOADING_EQUIPMENT_TYPES, true)) {
            $loadingEquipment = '';
        }

        $priceTerms = strtolower($this->stringValue($result['priceTerms'] ?? ''));
        if (! in_array($priceTerms, self::PRICE_TERMS, true)) {
            $priceTerms = '';
        }

        $specialRequirements = array_values(array_filter(
            is_array($result['specialRequirements'] ?? null) ? $result['specialRequirements'] : [],
            fn ($item) => is_string($item) && trim($item) !== '',
        ));

        $warnings = array_values(array_filter(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));
        if (! $isDocument) {
            $warnings[] = 'The attached photo was not recognized as a freight document.';
        }

        return [
            'isDocument' => $isDocument,
            'consigneeName' => $this->stringValue($result['consigneeName'] ?? ''),
            'consigneeTaxNumber' => $this->stringValue($result['consigneeTaxNumber'] ?? ''),
            'consigneeCity' => $this->stringValue($result['consigneeCity'] ?? ''),
            'consigneeCountryCode' => strtoupper($this->stringValue($result['consigneeCountryCode'] ?? '')),
            'title' => $this->stringValue($result['title'] ?? '', 'New load'),
            'transportType' => $transportType,
            'cargoType' => $this->stringValue($result['cargoType'] ?? ''),
            'goodsType' => $this->stringValue($result['goodsType'] ?? ''),
            'hsSearchTerms' => $this->stringValue($result['hsSearchTerms'] ?? ''),
            'hsCodes' => $this->hsCodeValues($result['hsCodes'] ?? null),
            'weightKg' => $this->numericValue($result['weightKg'] ?? 0),
            'pallets' => (int) $this->numericValue($result['pallets'] ?? 0),
            'bodyType' => $bodyType,
            'lengthM' => $this->numericValue($result['lengthM'] ?? 0),
            'widthM' => $this->numericValue($result['widthM'] ?? 0),
            'heightM' => $this->numericValue($result['heightM'] ?? 0),
            'volumeM3' => $this->numericValue($result['volumeM3'] ?? 0),
            'vehicleType' => $this->stringValue($result['vehicleType'] ?? ''),
            'loadingEquipment' => $loadingEquipment,
            'characteristics' => $this->stringValue($result['characteristics'] ?? ''),
            'specialRequirements' => $specialRequirements,
            'transportMode' => $this->stringValue($result['transportMode'] ?? ''),
            'deliveryProof' => $this->stringValue($result['deliveryProof'] ?? ''),
            'requiresTracking' => ($result['requiresTracking'] ?? false) === true,
            'pickupCity' => $this->stringValue($result['pickupCity'] ?? ''),
            'pickupCountryCode' => strtoupper($this->stringValue($result['pickupCountryCode'] ?? '')),
            'pickupAddress' => $this->stringValue($result['pickupAddress'] ?? ''),
            'pickupLatitude' => is_numeric($result['pickupLatitude'] ?? null) ? (float) $result['pickupLatitude'] : null,
            'pickupLongitude' => is_numeric($result['pickupLongitude'] ?? null) ? (float) $result['pickupLongitude'] : null,
            'pickupDate' => $this->dateValue($result['pickupDate'] ?? ''),
            'pickupDateTo' => $this->dateValue($result['pickupDateTo'] ?? ''),
            'pickupTimeFrom' => $this->timeValue($result['pickupTimeFrom'] ?? ''),
            'pickupTimeTo' => $this->timeValue($result['pickupTimeTo'] ?? ''),
            'deliveryCity' => $this->stringValue($result['deliveryCity'] ?? ''),
            'deliveryCountryCode' => strtoupper($this->stringValue($result['deliveryCountryCode'] ?? '')),
            'deliveryAddress' => $this->stringValue($result['deliveryAddress'] ?? ''),
            'deliveryLatitude' => is_numeric($result['deliveryLatitude'] ?? null) ? (float) $result['deliveryLatitude'] : null,
            'deliveryLongitude' => is_numeric($result['deliveryLongitude'] ?? null) ? (float) $result['deliveryLongitude'] : null,
            'deliveryDate' => $this->dateValue($result['deliveryDate'] ?? ''),
            'deliveryDateTo' => $this->dateValue($result['deliveryDateTo'] ?? ''),
            'deliveryTimeFrom' => $this->timeValue($result['deliveryTimeFrom'] ?? ''),
            'deliveryTimeTo' => $this->timeValue($result['deliveryTimeTo'] ?? ''),
            'currency' => $currency,
            'budget' => $this->numericValue($result['budget'] ?? 0),
            'priceTerms' => $priceTerms,
            'declaredValue' => $this->numericValue($result['declaredValue'] ?? 0),
            'declaredValueCurrency' => strtoupper($this->stringValue($result['declaredValueCurrency'] ?? '')),
            'incoterm' => strtoupper($this->stringValue($result['incoterm'] ?? '')),
            'paymentDueDays' => (int) $this->numericValue($result['paymentDueDays'] ?? 0),
            'temperatureMin' => is_numeric($result['temperatureMin'] ?? null) ? (float) $result['temperatureMin'] : null,
            'temperatureMax' => is_numeric($result['temperatureMax'] ?? null) ? (float) $result['temperatureMax'] : null,
            'requiresAdr' => ($result['requiresAdr'] ?? false) === true,
            'requiresTailLift' => ($result['requiresTailLift'] ?? false) === true,
            'tollRoadsIncluded' => ($result['tollRoadsIncluded'] ?? false) === true,
            'ferryIncluded' => ($result['ferryIncluded'] ?? false) === true,
            'cmrRequired' => ($result['cmrRequired'] ?? false) === true,
            'palletExchangeRequired' => ($result['palletExchangeRequired'] ?? false) === true,
            'customsRequired' => ($result['customsRequired'] ?? false) === true,
            'insuranceRequired' => ($result['insuranceRequired'] ?? false) === true,
            'certificationRequired' => ($result['certificationRequired'] ?? false) === true,
            'inspectionServicesRequired' => ($result['inspectionServicesRequired'] ?? false) === true,
            'isUrgent' => ($result['isUrgent'] ?? false) === true,
            'contactName' => $this->stringValue($result['contactName'] ?? ''),
            'contactPhone' => $this->stringValue($result['contactPhone'] ?? ''),
            'contactMobile' => $this->stringValue($result['contactMobile'] ?? ''),
            'contactFax' => $this->stringValue($result['contactFax'] ?? ''),
            'contactEmail' => $this->stringValue($result['contactEmail'] ?? ''),
            'bookingReference' => $this->stringValue($result['bookingReference'] ?? ''),
            'notes' => $this->stringValue($result['notes'] ?? ''),
            'customFields' => $this->customFieldsValue($result['customFields'] ?? null),
            'confidence' => max(0.0, min(1.0, $this->numericValue($result['confidence'] ?? 0))),
            'warnings' => $warnings,
        ];
    }

    private function customFieldsValue(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_array($item))
            ->map(fn ($item) => [
                'label' => $this->stringValue($item['label'] ?? ''),
                'value' => $this->stringValue($item['value'] ?? ''),
            ])
            ->filter(fn ($item) => $item['label'] !== '' && $item['value'] !== '')
            ->take(8)
            ->values()
            ->all();
    }

    private function hsCodeValues(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $item): array {
                $code = preg_replace('/\D+/', '', $this->stringValue($item['code'] ?? ''));

                return [
                    'code' => is_string($code) ? substr($code, 0, 6) : '',
                    'description' => $this->stringValue($item['description'] ?? ''),
                    'confidence' => max(0.0, min(1.0, $this->numericValue($item['confidence'] ?? 0.9))),
                ];
            })
            ->filter(fn (array $item): bool => preg_match('/^\d{6}$/', $item['code']) === 1)
            ->unique('code')
            ->take(10)
            ->values()
            ->all();
    }

    // Runs one catalog search per distinct product the extraction found (hsSearchTerms lists them
    // semicolon-separated, see documentSystemPrompt/textSystemPrompt) so a document or message that
    // mentions several different goods gets a matching code for each of them immediately, at scan
    // time, rather than only the single dominant product - the caller no longer needs a follow-up
    // text turn (or the separate "hs" guided chat mode) just to surface the rest.
    private function enrichHsCodes(array $result): array
    {
        $rawTerms = trim((string) ($result['hsSearchTerms'] ?: $result['goodsType'] ?? ''));
        $terms = collect(preg_split('/[;\n]+/', $rawTerms))
            ->map(fn ($term) => trim((string) $term))
            ->filter(fn ($term) => $term !== '')
            ->unique()
            ->take(5);

        $catalogCodes = $terms
            ->flatMap(fn ($term) => $this->hsCodes->search($term, 3))
            ->map(fn (array $match): array => [
                'code' => $match['code'],
                'description' => $match['description'],
                'confidence' => $match['confidence'],
            ]);

        $result['hsCodes'] = collect($result['hsCodes'] ?? [])
            ->concat($catalogCodes)
            ->unique('code')
            ->take(10)
            ->values()
            ->all();

        return $result;
    }

    private function dateValue(mixed $value): string
    {
        $value = $this->stringValue($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? $value : '';
    }

    private function timeValue(mixed $value): string
    {
        $value = $this->stringValue($value);
        $parsed = \DateTimeImmutable::createFromFormat('!H:i', $value);

        return $parsed && $parsed->format('H:i') === $value ? $value : '';
    }

    private function stringValue(mixed $value, string $fallback = ''): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return $fallback;
        }
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function numericValue(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['isDocument', 'consigneeName', 'consigneeTaxNumber', 'consigneeCity', 'consigneeCountryCode', 'title', 'transportType', 'cargoType', 'goodsType', 'hsSearchTerms', 'hsCodes', 'weightKg', 'pallets', 'bodyType', 'lengthM', 'widthM', 'heightM', 'volumeM3', 'vehicleType', 'loadingEquipment', 'characteristics', 'specialRequirements', 'transportMode', 'deliveryProof', 'requiresTracking', 'pickupCity', 'pickupCountryCode', 'pickupAddress', 'pickupLatitude', 'pickupLongitude', 'pickupDate', 'pickupDateTo', 'pickupTimeFrom', 'pickupTimeTo', 'deliveryCity', 'deliveryCountryCode', 'deliveryAddress', 'deliveryLatitude', 'deliveryLongitude', 'deliveryDate', 'deliveryDateTo', 'deliveryTimeFrom', 'deliveryTimeTo', 'currency', 'budget', 'priceTerms', 'declaredValue', 'declaredValueCurrency', 'incoterm', 'paymentDueDays', 'temperatureMin', 'temperatureMax', 'requiresAdr', 'requiresTailLift', 'tollRoadsIncluded', 'ferryIncluded', 'cmrRequired', 'palletExchangeRequired', 'customsRequired', 'insuranceRequired', 'certificationRequired', 'inspectionServicesRequired', 'isUrgent', 'contactName', 'contactPhone', 'contactMobile', 'contactFax', 'contactEmail', 'bookingReference', 'notes', 'customFields', 'confidence', 'warnings'],
            'properties' => [
                'isDocument' => ['type' => 'boolean', 'description' => 'True only when the image shows a freight/shipping document.'],
                'consigneeName' => ['type' => 'string', 'description' => 'Printed legal name of the consignee/customer company, or empty when not stated.'],
                'consigneeTaxNumber' => ['type' => 'string', 'description' => 'Printed tax, VAT or company ID used to identify the consignee/customer, or empty when not stated.'],
                'consigneeCity' => ['type' => 'string'],
                'consigneeCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code, or empty when unknown.'],
                'title' => ['type' => 'string'],
                'transportType' => ['type' => 'string', 'enum' => [...self::TRANSPORT_TYPES, ''], 'description' => 'road, air, or sea, or empty string if not stated.'],
                'cargoType' => ['type' => 'string'],
                'goodsType' => ['type' => 'string'],
                'hsSearchTerms' => ['type' => 'string', 'description' => 'One short English catalog search phrase (material, processing state, intended use when known) per distinct product; separate multiple products with semicolons so each gets its own HS code lookup.'],
                'hsCodes' => [
                    'type' => 'array',
                    'maxItems' => 10,
                    'description' => 'Six-digit HS codes explicitly stated in the source. Leave empty when none is stated; the server searches the catalog after extraction.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['code', 'description', 'confidence'],
                        'properties' => [
                            'code' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
                'weightKg' => ['type' => 'number'],
                'pallets' => ['type' => 'number', 'description' => 'Pallet or unit count, 0 if not stated.'],
                'bodyType' => ['type' => 'string', 'enum' => [...self::BODY_TYPES, ''], 'description' => 'Required trailer/body type, or empty string if not stated.'],
                'lengthM' => ['type' => 'number'],
                'widthM' => ['type' => 'number'],
                'heightM' => ['type' => 'number'],
                'volumeM3' => ['type' => 'number'],
                'vehicleType' => ['type' => 'string'],
                'loadingEquipment' => ['type' => 'string', 'enum' => [...self::LOADING_EQUIPMENT_TYPES, ''], 'description' => 'Loading/unloading equipment, or empty string if not stated.'],
                'characteristics' => ['type' => 'string', 'description' => 'Road/air handling characteristics such as ADR, CMR, GDP, TIR, Lift, Express, Non-DG, DG, TCG, MED, VAL.'],
                'specialRequirements' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Special handling requirements explicitly stated, mainly for air/sea shipments.'],
                'transportMode' => ['type' => 'string', 'description' => 'E.g. "Airport to airport", only for air/sea shipments.'],
                'deliveryProof' => ['type' => 'string', 'description' => 'Proof-of-delivery requirement, only for air/sea shipments.'],
                'requiresTracking' => ['type' => 'boolean'],
                'pickupCity' => ['type' => 'string'],
                'pickupCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code.'],
                'pickupAddress' => ['type' => 'string', 'description' => 'Street address, separate from the city.'],
                'pickupLatitude' => ['type' => ['number', 'null']],
                'pickupLongitude' => ['type' => ['number', 'null']],
                'pickupDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or empty string if not stated.'],
                'pickupDateTo' => ['type' => 'string', 'description' => 'YYYY-MM-DD end of a pickup date range, or empty string if not a range.'],
                'pickupTimeFrom' => ['type' => 'string', 'description' => 'HH:MM 24-hour, or empty string if not stated.'],
                'pickupTimeTo' => ['type' => 'string', 'description' => 'HH:MM 24-hour, or empty string if not stated.'],
                'deliveryCity' => ['type' => 'string'],
                'deliveryCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code.'],
                'deliveryAddress' => ['type' => 'string', 'description' => 'Street address, separate from the city.'],
                'deliveryLatitude' => ['type' => ['number', 'null']],
                'deliveryLongitude' => ['type' => ['number', 'null']],
                'deliveryDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or empty string if not stated.'],
                'deliveryDateTo' => ['type' => 'string', 'description' => 'YYYY-MM-DD end of a delivery date range, or empty string if not a range.'],
                'deliveryTimeFrom' => ['type' => 'string', 'description' => 'HH:MM 24-hour, or empty string if not stated.'],
                'deliveryTimeTo' => ['type' => 'string', 'description' => 'HH:MM 24-hour, or empty string if not stated.'],
                'currency' => ['type' => 'string'],
                'budget' => ['type' => 'number'],
                'priceTerms' => ['type' => 'string', 'enum' => [...self::PRICE_TERMS, ''], 'description' => 'fixed or negotiable, or empty string if not stated.'],
                'declaredValue' => ['type' => 'number', 'description' => 'Declared/insured cargo value, separate from the freight price.'],
                'declaredValueCurrency' => ['type' => 'string'],
                'incoterm' => ['type' => 'string'],
                'paymentDueDays' => ['type' => 'number'],
                'temperatureMin' => ['type' => ['number', 'null']],
                'temperatureMax' => ['type' => ['number', 'null']],
                'requiresAdr' => ['type' => 'boolean'],
                'requiresTailLift' => ['type' => 'boolean'],
                'tollRoadsIncluded' => ['type' => 'boolean'],
                'ferryIncluded' => ['type' => 'boolean'],
                'cmrRequired' => ['type' => 'boolean'],
                'palletExchangeRequired' => ['type' => 'boolean'],
                'customsRequired' => ['type' => 'boolean'],
                'insuranceRequired' => ['type' => 'boolean', 'description' => 'True only when cargo insurance is explicitly required.'],
                'certificationRequired' => ['type' => 'boolean', 'description' => 'True only when certification documents are explicitly required.'],
                'inspectionServicesRequired' => ['type' => 'boolean', 'description' => 'True only when cargo inspection services are explicitly required.'],
                'isUrgent' => ['type' => 'boolean'],
                'contactName' => ['type' => 'string'],
                'contactPhone' => ['type' => 'string'],
                'contactMobile' => ['type' => 'string'],
                'contactFax' => ['type' => 'string'],
                'contactEmail' => ['type' => 'string'],
                'bookingReference' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'customFields' => [
                    'type' => 'array',
                    'maxItems' => 8,
                    'description' => 'Distinct facts the shipper explicitly wants tracked as their own item, not folded into notes.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['label', 'value'],
                        'properties' => [
                            'label' => ['type' => 'string'],
                            'value' => ['type' => 'string'],
                        ],
                    ],
                ],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
