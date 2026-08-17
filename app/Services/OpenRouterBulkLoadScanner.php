<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

class OpenRouterBulkLoadScanner
{
    private const MAX_ATTEMPTS = 3;

    private const BODY_TYPES = ['Curtain', 'Box', 'Reefer', 'Mega', 'Tautliner', 'Flatbed'];

    private const MAX_ROWS = 50;

    public function scan(array $images): array
    {
        $systemPrompt = 'You read a document listing multiple freight loads (a spreadsheet export, a table, a manifest with several rows, '
            .'or several shipping orders on one page) to bulk-prefill load posting rows. Do not invent values you cannot read; '
            .'use an empty string or 0 for anything not shown in a given row. Extract every distinct load/row you can find, up to '.self::MAX_ROWS.'. '
            .'Read pickup and delivery locations as city names with their two-letter ISO 3166-1 alpha-2 country codes. '
            .'Read pickup and delivery dates separately as YYYY-MM-DD when shown. '
            .'Read cargo weight in kilograms, converting from other units if stated explicitly (e.g. lbs, tons). '
            .'Read the pallet or unit count as a plain number when a quantity is stated (e.g. "24 pallets" -> 24). '
            .'Read the required trailer/body type only when explicitly stated or clearly implied (e.g. "cerada"/"tarpaulin"/"curtain-sider" means Curtain; '
            .'"hladnjaca"/"refrigerated" means Reefer; "furgon"/"box" means Box), choosing exactly one of: Curtain, Box, Reefer, Mega, Tautliner, Flatbed - or empty if not stated. '
            .'Read the currency from the symbol or code shown and return its ISO 4217 code. '
            .'Put ONLY leftover information with no dedicated field into notes for that row - never repeat the pallet count, dates, or body type inside notes. '
            .'Set isDocument to true only when the image really shows a list of freight loads; otherwise set it to false and return an empty rows array. '
            .'Return only the fields requested in the JSON schema.';
        $userPrompt = 'Read every load row in this document. For each row, extract a short title, cargo type, goods type/description, weight in kilograms, '
            .'pallet/unit count, required trailer body type if stated, pickup city/country code/date, delivery city/country code/date, currency, agreed price, '
            .'booking or reference number, and any leftover notes.';

        $payload = $this->requestPayload($images, $systemPrompt, $userPrompt);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken((string) config('services.openrouter.api_key'))
                    ->acceptJson()
                    ->timeout(120)
                    ->withHeaders([
                        'HTTP-Referer' => config('app.url'),
                        'X-Title' => 'Freightbook.ai Bulk Load Scanner',
                    ])
                    ->post((string) config('services.openrouter.url'), $payload);

                $this->ensureSuccessful($response);
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
                        'images' => ['The AI could not read this document after several attempts. Please try again.'],
                    ]);
                }

                Log::warning('Bulk load scan AI call failed; retrying automatically.', [
                    'attempt' => $attempt,
                    'max_attempts' => self::MAX_ATTEMPTS,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function requestPayload(array $images, string $systemPrompt, string $userPrompt): array
    {
        return [
            'model' => config('services.openrouter.model'),
            'temperature' => 0,
            'provider' => ['require_parameters' => true],
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => [
                    'name' => 'freightbook_bulk_load_document',
                    'strict' => true,
                    'schema' => $this->schema(),
                ],
            ],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $userPrompt],
                        ...array_map(fn (array $image) => [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => 'data:'.($image['mimeType'] ?? 'image/jpeg').';base64,'.$image['base64'],
                            ],
                        ], $images),
                    ],
                ],
            ],
        ];
    }

    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw ValidationException::withMessages([
            'images' => ['AI scanning is not available right now. Please try again.'],
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
        $rawRows = is_array($result['rows'] ?? null) ? array_slice($result['rows'], 0, self::MAX_ROWS) : [];

        $rows = array_values(array_map(fn (mixed $row) => $this->normalizeRow(is_array($row) ? $row : []), $rawRows));

        $warnings = array_values(array_filter(
            is_array($result['warnings'] ?? null) ? $result['warnings'] : [],
            fn ($warning) => is_string($warning) && trim($warning) !== '',
        ));
        if (! $isDocument) {
            $warnings[] = 'The attached photo was not recognized as a list of freight loads.';
            $rows = [];
        } elseif ($rows === []) {
            $warnings[] = 'No individual load rows could be read from this document.';
        }

        return [
            'isDocument' => $isDocument,
            'rows' => $rows,
            'warnings' => $warnings,
        ];
    }

    private function normalizeRow(array $row): array
    {
        $currency = strtoupper($this->stringValue($row['currency'] ?? ''));
        if ($currency === '') {
            $currency = 'EUR';
        }

        $bodyType = $this->stringValue($row['bodyType'] ?? '');
        if (! in_array($bodyType, self::BODY_TYPES, true)) {
            $bodyType = '';
        }

        return [
            'title' => $this->stringValue($row['title'] ?? '', 'New load'),
            'cargoType' => $this->stringValue($row['cargoType'] ?? ''),
            'goodsType' => $this->stringValue($row['goodsType'] ?? ''),
            'weightKg' => $this->numericValue($row['weightKg'] ?? 0),
            'pallets' => (int) $this->numericValue($row['pallets'] ?? 0),
            'bodyType' => $bodyType,
            'pickupCity' => $this->stringValue($row['pickupCity'] ?? ''),
            'pickupCountryCode' => strtoupper($this->stringValue($row['pickupCountryCode'] ?? '')),
            'pickupDate' => $this->dateValue($row['pickupDate'] ?? ''),
            'deliveryCity' => $this->stringValue($row['deliveryCity'] ?? ''),
            'deliveryCountryCode' => strtoupper($this->stringValue($row['deliveryCountryCode'] ?? '')),
            'deliveryDate' => $this->dateValue($row['deliveryDate'] ?? ''),
            'currency' => $currency,
            'budget' => $this->numericValue($row['budget'] ?? 0),
            'bookingReference' => $this->stringValue($row['bookingReference'] ?? ''),
            'notes' => $this->stringValue($row['notes'] ?? ''),
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

    private function rowSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'cargoType', 'goodsType', 'weightKg', 'pallets', 'bodyType', 'pickupCity', 'pickupCountryCode', 'pickupDate', 'deliveryCity', 'deliveryCountryCode', 'deliveryDate', 'currency', 'budget', 'bookingReference', 'notes'],
            'properties' => [
                'title' => ['type' => 'string'],
                'cargoType' => ['type' => 'string'],
                'goodsType' => ['type' => 'string'],
                'weightKg' => ['type' => 'number'],
                'pallets' => ['type' => 'number'],
                'bodyType' => ['type' => 'string', 'enum' => [...self::BODY_TYPES, '']],
                'pickupCity' => ['type' => 'string'],
                'pickupCountryCode' => ['type' => 'string'],
                'pickupDate' => ['type' => 'string'],
                'deliveryCity' => ['type' => 'string'],
                'deliveryCountryCode' => ['type' => 'string'],
                'deliveryDate' => ['type' => 'string'],
                'currency' => ['type' => 'string'],
                'budget' => ['type' => 'number'],
                'bookingReference' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
            ],
        ];
    }

    private function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['isDocument', 'rows', 'warnings'],
            'properties' => [
                'isDocument' => ['type' => 'boolean', 'description' => 'True only when the image shows a list of freight loads.'],
                'rows' => ['type' => 'array', 'maxItems' => self::MAX_ROWS, 'items' => $this->rowSchema()],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
