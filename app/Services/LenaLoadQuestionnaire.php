<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Collection;

class LenaLoadQuestionnaire
{
    // 'options' marks steps the frontend renders with real selectable-choice buttons (see
    // questionnaireSuggestions() in useLenaEmbeddedMessages.tsx) rather than just a free-text
    // input - DispatchChatController uses this to stop the AI restating those option values in
    // its own question text (redundant with the buttons already shown) and to phrase free-input
    // steps as a direct "enter this value" ask instead. Descriptions here were trimmed to match:
    // they used to spell out the exact option words, which is what caused the restating.
    private const STEPS = [
        'title' => ['description' => 'a short load title', 'options' => false],
        'transportType' => ['description' => 'the transport type', 'options' => true],
        'goodsType' => ['description' => 'the goods or cargo type', 'options' => false],
        'weight' => ['description' => 'the cargo weight in kilograms', 'options' => false],
        'pallets' => ['description' => 'the pallet or unit count, including zero or none', 'options' => false],
        'bodyType' => ['description' => 'the trailer or body type, or whether none is required', 'options' => true],
        'dimensions' => ['description' => 'the dimensions or volume, or whether they are unknown/not needed', 'options' => false],
        'vehicleType' => ['description' => 'the required vehicle type, or whether there is no preference', 'options' => true],
        'loadingEquipment' => ['description' => 'loading or unloading equipment requirements, or none', 'options' => true],
        'characteristics' => ['description' => 'transport characteristics, or none', 'options' => true],
        'specialRequirements' => ['description' => 'one or more special requirements or notes, or none; explicitly mention that multiple options may be selected', 'options' => true],
        'transportMode' => ['description' => 'the air/sea transport mode, or none', 'options' => true],
        'deliveryProof' => ['description' => 'the proof-of-delivery requirement, or none', 'options' => true],
        'pickup' => ['description' => 'the pickup city, country, and address if available', 'options' => false],
        'pickupDate' => ['description' => 'the pickup date or date/time window', 'options' => false],
        'delivery' => ['description' => 'the delivery city, country, and address if available', 'options' => false],
        'deliveryDate' => ['description' => 'the delivery date or date/time window', 'options' => false],
        'budget' => ['description' => 'the freight price and currency', 'options' => false],
        'priceTerms' => ['description' => 'the pricing terms', 'options' => true],
        'declaredValue' => ['description' => 'the declared cargo value and currency, or none', 'options' => false],
        'terms' => ['description' => 'Incoterm and deferred-payment terms, or none', 'options' => true],
        'temperature' => ['description' => 'temperature-control requirements, or none', 'options' => false],
        'requirements' => ['description' => 'special handling and service requirements, or none', 'options' => true],
        'contact' => ['description' => 'the contact name and available phone or email details, or none', 'options' => true],
        'notes' => ['description' => 'any final notes, booking reference, or custom items, or none', 'options' => false],
    ];

    public function nextStep(array $draft, Collection $messages, int $aiDispatcherId): ?array
    {
        $answeredWithoutValue = $this->negativeAnswersByStep($messages, $aiDispatcherId);

        foreach (self::STEPS as $key => $meta) {
            if ($this->isSkipped($key, $draft) || $this->hasValue($key, $draft) || isset($answeredWithoutValue[$key])) {
                continue;
            }

            return ['key' => $key, 'description' => $meta['description'], 'hasOptions' => $meta['options']];
        }

        return null;
    }

    public function hasCompleteReadyMarker(Collection $messages): bool
    {
        return $messages->contains(fn (Message $message) => str_contains((string) $message->body, '[[LOAD_READY_TO_POST:complete]]'));
    }

    private function negativeAnswersByStep(Collection $messages, int $aiDispatcherId): array
    {
        $pendingStep = null;
        $answered = [];

        foreach ($messages->sortBy('sent_at') as $message) {
            if ((int) $message->sender_user_id === $aiDispatcherId) {
                if (preg_match('/\[\[LENA_STEP:([a-zA-Z]+)\]\]/', (string) $message->body, $match) === 1) {
                    $pendingStep = $match[1];
                }

                continue;
            }

            if (preg_match('/\[\[LENA_SKIP:([a-zA-Z]+)\]\]/', (string) $message->body, $match) === 1
                && array_key_exists($match[1], self::STEPS)) {
                $answered[$match[1]] = true;
                $pendingStep = null;

                continue;
            }

            if ($pendingStep && $this->isNegativeOrEmptyAnswer((string) $message->body)) {
                $answered[$pendingStep] = true;
            }
        }

        return $answered;
    }

    private function isNegativeOrEmptyAnswer(string $answer): bool
    {
        $normalized = mb_strtolower(trim($answer));

        return preg_match('/^(?:0|ne|nema|nemam|nikakv\w*|bez|ništa|nista|nije potrebno|nije poznato|nije navedeno|no|none|nothing|unknown|not needed|not specified|no preference|nein|keine|keiner|keins|nichts|unbekannt|nicht erforderlich|nicht angegeben)(?:\b.*)?[.!]?$/ui', $normalized) === 1;
    }

    private function isSkipped(string $key, array $draft): bool
    {
        $transportType = $draft['transportType'] ?? '';

        // Goods held in a warehouse are not carried anywhere, so nothing about the vehicle or the
        // journey is asked - only what arrives, where it is stored and for how long.
        if ($transportType === 'warehouse') {
            return in_array($key, ['bodyType', 'vehicleType', 'transportMode', 'deliveryProof', 'priceTerms'], true);
        }

        return in_array($key, ['transportMode', 'deliveryProof'], true) && $transportType === 'road';
    }

    private function hasValue(string $key, array $draft): bool
    {
        $filled = fn (string $field): bool => filled($draft[$field] ?? null);
        $positive = fn (string $field): bool => is_numeric($draft[$field] ?? null) && (float) $draft[$field] > 0;
        $true = fn (string $field): bool => ($draft[$field] ?? false) === true;

        return match ($key) {
            'title' => $filled('title') && mb_strtolower(trim((string) $draft['title'])) !== 'new load',
            'transportType' => in_array($draft['transportType'] ?? '', ['road', 'air', 'sea', 'warehouse'], true),
            'goodsType' => $filled('goodsType') || $filled('cargoType'),
            'weight' => $positive('weightKg'),
            'pallets' => $positive('pallets'),
            'bodyType' => $filled('bodyType'),
            'dimensions' => $positive('lengthM') || $positive('widthM') || $positive('heightM') || $positive('volumeM3'),
            'vehicleType' => $filled('vehicleType'),
            'loadingEquipment' => $filled('loadingEquipment'),
            'characteristics' => $filled('characteristics'),
            'specialRequirements' => ! empty($draft['specialRequirements']),
            'transportMode' => $filled('transportMode'),
            'deliveryProof' => $filled('deliveryProof'),
            'pickup' => $filled('pickupCity') || $filled('pickupCountryCode') || $filled('pickupAddress'),
            'pickupDate' => $filled('pickupDate') || $filled('pickupTimeFrom'),
            'delivery' => $filled('deliveryCity') || $filled('deliveryCountryCode') || $filled('deliveryAddress'),
            'deliveryDate' => $filled('deliveryDate') || $filled('deliveryTimeFrom'),
            'budget' => $positive('budget') && $filled('currency'),
            'priceTerms' => in_array($draft['priceTerms'] ?? '', ['fixed', 'negotiable'], true),
            'declaredValue' => $positive('declaredValue'),
            'terms' => $filled('incoterm') || $positive('paymentDueDays'),
            // Both ends of the range are required, not just one - a range half-answered with only
            // a minimum must keep this step pending rather than letting the server's own next-step
            // marker silently jump ahead while a reply is still mid-way through asking for the
            // maximum (the user can still explicitly skip the rest via [[LENA_SKIP:temperature]]).
            'temperature' => ($draft['temperatureMin'] ?? null) !== null && ($draft['temperatureMax'] ?? null) !== null,
            'requirements' => $true('requiresAdr') || $true('requiresTailLift') || $true('tollRoadsIncluded') || $true('ferryIncluded') || $true('cmrRequired') || $true('palletExchangeRequired') || $true('customsRequired') || $true('insuranceRequired') || $true('certificationRequired') || $true('inspectionServicesRequired') || $true('isUrgent') || $true('requiresTracking'),
            'contact' => $filled('contactName') || $filled('contactPhone') || $filled('contactMobile') || $filled('contactFax') || $filled('contactEmail'),
            'notes' => $filled('notes') || $filled('bookingReference') || ! empty($draft['customFields']),
            default => false,
        };
    }
}
