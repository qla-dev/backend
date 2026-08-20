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

    public function scan(array $images): array
    {
        $userPrompt = 'Read a short title summarizing the load, the cargo type (e.g. Pallets, Machinery, Electronics), the goods type/description, '
            .'the weight in kilograms, the pallet/unit count, the required trailer body type if stated, '
            .'dimensions and volume, required vehicle type, '
            .'the pickup city, country code and date, the delivery city, country code and date, the currency, the agreed price or rate, '
            .'Incoterm, deferred payment days, temperature range, ADR, tail-lift and urgency requirements, contact name/phone/email, '
            .'the booking or reference number, and any other short notes that do not belong in a dedicated field.';

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

        return $this->run($this->documentSystemPrompt(), $content, 'images');
    }

    public function scanText(string $description): array
    {
        $userPrompt = 'The shipper described the load in their own words below. Extract a short title, the cargo type '
            .'(e.g. Pallets, Machinery, Electronics), the goods type/description, the weight in kilograms, the pallet/unit count, '
            .'the required trailer body type if stated, the pickup city, country code and date, the delivery city, country code and date, '
            .'dimensions, volume, vehicle, the currency, agreed price or rate, Incoterm, deferred payment days, temperature range, ADR, tail-lift, urgency, contact details, '
            .'the booking or reference number, and any other short notes that do not belong in a dedicated field.'
            ."\n\nDescription:\n".$description;

        $content = [['type' => 'text', 'text' => $userPrompt]];

        return $this->run($this->textSystemPrompt(), $content, 'description');
    }

    private function documentSystemPrompt(): string
    {
        return 'You read a freight document (a shipping order, rate confirmation, bill of lading, cargo manifest, or booking note) '
            .'to prefill a new load posting form. Do not invent values you cannot read; use an empty string, 0, or false for anything not shown. '
            .'Read the pickup and delivery locations as city names, and their two-letter ISO 3166-1 alpha-2 country codes. '
            .'Read the pickup date and delivery date separately as YYYY-MM-DD; if only one date is shown, use it for whichever of the two it clearly refers to and leave the other empty. '
            .'Read the cargo weight in kilograms, converting from other units if the document states them explicitly (e.g. lbs, tons). '
            .'Read the pallet or unit count as a plain number when the document states a quantity (e.g. "24 pallets" -> 24). '
            .'Read the required trailer/body type only when explicitly stated or clearly implied (e.g. "cerada"/"tarpaulin"/"curtain-sider" means Curtain; "hladnjaca"/"refrigerated" means Reefer; "furgon"/"box" means Box), choosing exactly one of: Curtain, Box, Reefer, Mega, Tautliner, Flatbed - or an empty string if not stated. '
            .'Extract dimensions in meters, volume in cubic meters, vehicle type, Incoterm, deferred payment days, temperature range in Celsius, ADR/tail-lift/urgency flags, and contact details only when explicitly present. '
            .'Read the currency from the symbol or code printed on the document and return its ISO 4217 code. '
            .'Put ONLY leftover information that has no dedicated field (e.g. special handling instructions) in notes - never repeat the pallet count, dates, or body type inside notes since those already have their own fields. '
            .'Set isDocument to true only when the image really shows a freight/shipping document; otherwise set it to false and do not invent data. '
            .'Return only the fields requested in the JSON schema.';
    }

    private function textSystemPrompt(): string
    {
        return 'You read a free-text description of a freight load, written by a shipper in plain language (any of English, Bosnian/Croatian/Serbian, or German), '
            .'to prefill a new load posting form. Do not invent values that are not stated or clearly implied; use an empty string, 0, or false for anything not mentioned. '
            .'Read the pickup and delivery locations as city names, and their two-letter ISO 3166-1 alpha-2 country codes. '
            .'Read the pickup date and delivery date separately as YYYY-MM-DD; if only one date is mentioned, use it for whichever of the two it clearly refers to and leave the other empty. '
            .'Read the cargo weight in kilograms, converting from other units if stated explicitly (e.g. lbs, tons). '
            .'Read the pallet or unit count as a plain number when a quantity is mentioned (e.g. "24 paleta" -> 24). '
            .'Read the required trailer/body type only when explicitly stated or clearly implied (e.g. "cerada"/"tarpaulin"/"curtain-sider" means Curtain; "hladnjaca"/"refrigerated" means Reefer; "furgon"/"box" means Box), choosing exactly one of: Curtain, Box, Reefer, Mega, Tautliner, Flatbed - or an empty string if not stated. '
            .'Extract dimensions in meters, volume in cubic meters, vehicle type, Incoterm, deferred payment days, temperature range in Celsius, ADR/tail-lift/urgency flags, and contact details only when stated. '
            .'Read the currency from the symbol or code mentioned and return its ISO 4217 code, defaulting to EUR if a price is given without a currency. '
            .'Put ONLY leftover information that has no dedicated field (e.g. special handling instructions) in notes - never repeat the pallet count, dates, or body type inside notes since those already have their own fields. '
            .'Set isDocument to true whenever the text describes a freight load (even briefly); set it to false only when the text is unrelated to freight/shipping. '
            .'Return only the fields requested in the JSON schema.';
    }

    private function run(string $systemPrompt, array $content, string $errorField): array
    {
        $payload = $this->requestPayload($systemPrompt, $content);

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

                $this->ensureSuccessful($response, $errorField);
                $result = json_decode($this->outputText($response->json()), true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($result)) {
                    throw new RuntimeException('The AI service returned an invalid scan result.');
                }

                return $this->normalizeResult($result);
            } catch (ConnectionException|JsonException|RuntimeException|ValidationException $exception) {
                if ($attempt === self::MAX_ATTEMPTS) {
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

    private function requestPayload(string $systemPrompt, array $content): array
    {
        return [
            'model' => config('services.openrouter.model'),
            'temperature' => 0,
            'provider' => ['require_parameters' => true],
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

        $warnings = array_values(array_filter(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));
        if (! $isDocument) {
            $warnings[] = 'The attached photo was not recognized as a freight document.';
        }

        return [
            'isDocument' => $isDocument,
            'title' => $this->stringValue($result['title'] ?? '', 'New load'),
            'cargoType' => $this->stringValue($result['cargoType'] ?? ''),
            'goodsType' => $this->stringValue($result['goodsType'] ?? ''),
            'weightKg' => $this->numericValue($result['weightKg'] ?? 0),
            'pallets' => (int) $this->numericValue($result['pallets'] ?? 0),
            'bodyType' => $bodyType,
            'lengthM' => $this->numericValue($result['lengthM'] ?? 0),
            'widthM' => $this->numericValue($result['widthM'] ?? 0),
            'heightM' => $this->numericValue($result['heightM'] ?? 0),
            'volumeM3' => $this->numericValue($result['volumeM3'] ?? 0),
            'vehicleType' => $this->stringValue($result['vehicleType'] ?? ''),
            'pickupCity' => $this->stringValue($result['pickupCity'] ?? ''),
            'pickupCountryCode' => strtoupper($this->stringValue($result['pickupCountryCode'] ?? '')),
            'pickupDate' => $this->dateValue($result['pickupDate'] ?? ''),
            'deliveryCity' => $this->stringValue($result['deliveryCity'] ?? ''),
            'deliveryCountryCode' => strtoupper($this->stringValue($result['deliveryCountryCode'] ?? '')),
            'deliveryDate' => $this->dateValue($result['deliveryDate'] ?? ''),
            'currency' => $currency,
            'budget' => $this->numericValue($result['budget'] ?? 0),
            'incoterm' => strtoupper($this->stringValue($result['incoterm'] ?? '')),
            'paymentDueDays' => (int) $this->numericValue($result['paymentDueDays'] ?? 0),
            'temperatureMin' => is_numeric($result['temperatureMin'] ?? null) ? (float) $result['temperatureMin'] : null,
            'temperatureMax' => is_numeric($result['temperatureMax'] ?? null) ? (float) $result['temperatureMax'] : null,
            'requiresAdr' => ($result['requiresAdr'] ?? false) === true,
            'requiresTailLift' => ($result['requiresTailLift'] ?? false) === true,
            'isUrgent' => ($result['isUrgent'] ?? false) === true,
            'contactName' => $this->stringValue($result['contactName'] ?? ''),
            'contactPhone' => $this->stringValue($result['contactPhone'] ?? ''),
            'contactEmail' => $this->stringValue($result['contactEmail'] ?? ''),
            'bookingReference' => $this->stringValue($result['bookingReference'] ?? ''),
            'notes' => $this->stringValue($result['notes'] ?? ''),
            'confidence' => max(0.0, min(1.0, $this->numericValue($result['confidence'] ?? 0))),
            'warnings' => $warnings,
        ];
    }

    private function dateValue(mixed $value): string
    {
        $value = $this->stringValue($value);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? $value : '';
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
            'required' => ['isDocument', 'title', 'cargoType', 'goodsType', 'weightKg', 'pallets', 'bodyType', 'lengthM', 'widthM', 'heightM', 'volumeM3', 'vehicleType', 'pickupCity', 'pickupCountryCode', 'pickupDate', 'deliveryCity', 'deliveryCountryCode', 'deliveryDate', 'currency', 'budget', 'incoterm', 'paymentDueDays', 'temperatureMin', 'temperatureMax', 'requiresAdr', 'requiresTailLift', 'isUrgent', 'contactName', 'contactPhone', 'contactEmail', 'bookingReference', 'notes', 'confidence', 'warnings'],
            'properties' => [
                'isDocument' => ['type' => 'boolean', 'description' => 'True only when the image shows a freight/shipping document.'],
                'title' => ['type' => 'string'],
                'cargoType' => ['type' => 'string'],
                'goodsType' => ['type' => 'string'],
                'weightKg' => ['type' => 'number'],
                'pallets' => ['type' => 'number', 'description' => 'Pallet or unit count, 0 if not stated.'],
                'bodyType' => ['type' => 'string', 'enum' => [...self::BODY_TYPES, ''], 'description' => 'Required trailer/body type, or empty string if not stated.'],
                'lengthM' => ['type' => 'number'],
                'widthM' => ['type' => 'number'],
                'heightM' => ['type' => 'number'],
                'volumeM3' => ['type' => 'number'],
                'vehicleType' => ['type' => 'string'],
                'pickupCity' => ['type' => 'string'],
                'pickupCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code.'],
                'pickupDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or empty string if not stated.'],
                'deliveryCity' => ['type' => 'string'],
                'deliveryCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code.'],
                'deliveryDate' => ['type' => 'string', 'description' => 'YYYY-MM-DD, or empty string if not stated.'],
                'currency' => ['type' => 'string'],
                'budget' => ['type' => 'number'],
                'incoterm' => ['type' => 'string'],
                'paymentDueDays' => ['type' => 'number'],
                'temperatureMin' => ['type' => ['number', 'null']],
                'temperatureMax' => ['type' => ['number', 'null']],
                'requiresAdr' => ['type' => 'boolean'],
                'requiresTailLift' => ['type' => 'boolean'],
                'isUrgent' => ['type' => 'boolean'],
                'contactName' => ['type' => 'string'],
                'contactPhone' => ['type' => 'string'],
                'contactEmail' => ['type' => 'string'],
                'bookingReference' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
