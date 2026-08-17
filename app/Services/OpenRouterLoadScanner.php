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

    public function scan(array $images): array
    {
        $systemPrompt = 'You read a freight document (a shipping order, rate confirmation, bill of lading, cargo manifest, or booking note) '
            .'to prefill a new load posting form. Do not invent values you cannot read; use an empty string, 0, or false for anything not shown. '
            .'Read the pickup and delivery locations as city names, and their two-letter ISO 3166-1 alpha-2 country codes. '
            .'Read the cargo weight in kilograms, converting from other units if the document states them explicitly (e.g. lbs, tons). '
            .'Read the currency from the symbol or code printed on the document and return its ISO 4217 code. '
            .'Set isDocument to true only when the image really shows a freight/shipping document; otherwise set it to false and do not invent data. '
            .'Return only the fields requested in the JSON schema.';
        $userPrompt = 'Read a short title summarizing the load, the cargo type (e.g. Pallets, Machinery, Electronics), the goods type/description, '
            .'the weight in kilograms, the pickup city and country code, the delivery city and country code, the currency, the agreed price or rate, '
            .'the booking or reference number, and any other short notes.';

        $payload = $this->requestPayload($images, $systemPrompt, $userPrompt);

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

                Log::warning('Load scan AI call failed; retrying automatically.', [
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
                    'name' => 'freightbook_load_document',
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

        $currency = strtoupper($this->stringValue($result['currency'] ?? ''));
        if ($currency === '') {
            $currency = 'EUR';
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
            'pickupCity' => $this->stringValue($result['pickupCity'] ?? ''),
            'pickupCountryCode' => strtoupper($this->stringValue($result['pickupCountryCode'] ?? '')),
            'deliveryCity' => $this->stringValue($result['deliveryCity'] ?? ''),
            'deliveryCountryCode' => strtoupper($this->stringValue($result['deliveryCountryCode'] ?? '')),
            'currency' => $currency,
            'budget' => $this->numericValue($result['budget'] ?? 0),
            'bookingReference' => $this->stringValue($result['bookingReference'] ?? ''),
            'notes' => $this->stringValue($result['notes'] ?? ''),
            'confidence' => max(0.0, min(1.0, $this->numericValue($result['confidence'] ?? 0))),
            'warnings' => $warnings,
        ];
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
            'required' => ['isDocument', 'title', 'cargoType', 'goodsType', 'weightKg', 'pickupCity', 'pickupCountryCode', 'deliveryCity', 'deliveryCountryCode', 'currency', 'budget', 'bookingReference', 'notes', 'confidence', 'warnings'],
            'properties' => [
                'isDocument' => ['type' => 'boolean', 'description' => 'True only when the image shows a freight/shipping document.'],
                'title' => ['type' => 'string'],
                'cargoType' => ['type' => 'string'],
                'goodsType' => ['type' => 'string'],
                'weightKg' => ['type' => 'number'],
                'pickupCity' => ['type' => 'string'],
                'pickupCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code.'],
                'deliveryCity' => ['type' => 'string'],
                'deliveryCountryCode' => ['type' => 'string', 'description' => 'Two-letter ISO 3166-1 alpha-2 country code.'],
                'currency' => ['type' => 'string'],
                'budget' => ['type' => 'number'],
                'bookingReference' => ['type' => 'string'],
                'notes' => ['type' => 'string'],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                'warnings' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
