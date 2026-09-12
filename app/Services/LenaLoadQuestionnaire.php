<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Collection;

class LenaLoadQuestionnaire
{


    public function nextStep(array $draft, Collection $messages, int $aiDispatcherId): ?array
    {
        $answeredWithoutValue = $this->negativeAnswersByStep($messages, $aiDispatcherId);

        foreach (app(LenaCatalog::class)->schema()['steps'] as $key => $meta) {
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
                && array_key_exists($match[1], app(LenaCatalog::class)->schema()['steps'])) {
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
            return in_array($key, ['bodyType', 'vehicleType', 'transportMode', 'deliveryProof', 'priceTerms'], true)
                || ($key === 'warehouse' && ($draft['storageTarget'] ?? '') === 'exchange');
        }

        if (in_array($key, ['storageTarget', 'warehouse'], true)) {
            return true;
        }

        return in_array($key, ['transportMode', 'deliveryProof'], true) && $transportType === 'road';
    }

    private function hasValue(string $key, array $draft): bool
    {
        $filled = fn (string $field): bool => filled($draft[$field] ?? null);
        $positive = fn (string $field): bool => is_numeric($draft[$field] ?? null) && (float) $draft[$field] > 0;
        $true = fn (string $field): bool => ($draft[$field] ?? false) === true;

        return match ($key) {
            'storageTarget' => in_array($draft['storageTarget'] ?? '', ['own', 'exchange'], true),
            'warehouse' => ($draft['storageTarget'] ?? '') === 'exchange' || $positive('warehouseId'),
            'title' => $filled('title') && mb_strtolower(trim((string) $draft['title'])) !== 'new load',
            'transportType' => in_array($draft['transportType'] ?? '', ['road', 'air', 'sea', 'rail', 'warehouse'], true),
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
