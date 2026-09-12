<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Collection;

class LenaLoadQuestionnaire
{
    public function nextStep(array $draft, Collection $messages, int $aiDispatcherId): ?array
    {
        $answeredWithoutValue = $this->negativeAnswersByStep($messages, $aiDispatcherId);
        $catalog = app(LenaCatalog::class);
        $transport = $this->transport($draft);

        // Only the steps this transport type actually has: a warehouse booking is never asked
        // about a vehicle, and a truck is never asked which container types it needs.
        foreach ($catalog->steps($transport) as $key => $meta) {
            if ($this->isSkipped($meta, $draft) || $this->hasValue($key, $draft) || isset($answeredWithoutValue[$key])) {
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

    private function transport(array $draft): string
    {
        $transport = (string) ($draft['transportType'] ?? '');

        return in_array($transport, LenaCatalog::TRANSPORTS, true) ? $transport : 'road';
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

    // A step the schema marks as conditional - goods going to the warehouse exchange have no
    // receiving warehouse of the user's own to name.
    private function isSkipped(array $meta, array $draft): bool
    {
        $condition = $meta['skip_when'] ?? null;

        return $condition !== null && ($draft[$condition['field']] ?? null) === $condition['equals'];
    }

    private function hasValue(string $key, array $draft): bool
    {
        $filled = fn (string $field): bool => filled($draft[$field] ?? null);
        $positive = fn (string $field): bool => is_numeric($draft[$field] ?? null) && (float) $draft[$field] > 0;
        $true = fn (string $field): bool => ($draft[$field] ?? false) === true;
        $any = fn (array $fields) => collect($fields)->contains(fn (string $field) => filled($draft[$field] ?? null));

        return match ($key) {
            'storageTarget' => in_array($draft['storageTarget'] ?? '', ['own', 'exchange'], true),
            'warehouse' => ($draft['storageTarget'] ?? '') === 'exchange' || $positive('warehouseId'),
            'title' => $filled('title') && mb_strtolower(trim((string) $draft['title'])) !== 'new load',
            'transportType' => in_array($draft['transportType'] ?? '', LenaCatalog::TRANSPORTS, true),
            'customer' => $any(['consigneeName', 'consignee', 'customerName']),
            'cargoType' => $filled('cargoType'),
            'goodsType' => $filled('goodsType') || $filled('cargoType'),
            'hsCode' => ! empty($draft['hsCodes']) || $filled('hsSearchTerms'),
            'packaging' => $filled('quantityMeasure'),
            'weight' => $positive('weightKg'),
            'pallets' => $positive('pallets'),
            'dimensions' => $positive('lengthM') || $positive('widthM') || $positive('heightM') || $positive('volumeM3'),
            'containers' => ! empty($draft['containerSelections']),
            'bodyType' => $filled('bodyType'),
            'vehicleType' => $filled('vehicleType'),
            'loadingEquipment' => $filled('loadingEquipment'),
            'storageEquipment' => ! empty($draft['warehouseEquipment']),
            'characteristics' => $filled('characteristics'),
            'dangerousGoods' => $any(['dgUnNumber', 'dgImoClass', 'dgPackingGroup', 'dgProperShippingName']),
            'outOfGauge' => $filled('oogInGauge'),
            'specialRequirements' => ! empty($draft['specialRequirements']),
            'transportMode' => $filled('transportMode'),
            'deliveryProof' => $filled('deliveryProof'),
            'pickup' => $any(['pickupCity', 'pickupCountryCode', 'pickupAddress', 'pickupPort', 'pickupAirport']),
            'pickupDate' => $filled('pickupDate') || $filled('pickupTimeFrom'),
            'delivery' => $any(['deliveryCity', 'deliveryCountryCode', 'deliveryAddress', 'deliveryPort', 'deliveryAirport']),
            'deliveryDate' => $filled('deliveryDate') || $filled('deliveryTimeFrom'),
            'transitDays' => $positive('transitDays'),
            'extraStops' => ! empty($draft['extraPickups']) || ! empty($draft['extraDeliveries']),
            'storageType' => $filled('warehouseStorageType'),
            'storagePeriod' => $filled('warehouseStartDate') || $true('warehouseIsOngoing'),
            'storageTemperature' => ($draft['warehouseTemperatureMin'] ?? null) !== null && ($draft['warehouseTemperatureMax'] ?? null) !== null,
            'storageServices' => $true('warehouseRequiresCustomsBonded') || $true('warehouseRequiresRacking') || $true('warehouseRequiresInsurance') || $true('warehouseRequiresSecurity') || $true('warehouseFoodPharma') || $true('warehouseFragile'),
            'storageRate' => $filled('warehouseRateUnit'),
            'budget' => $positive('budget') && $filled('currency'),
            'priceTerms' => in_array($draft['priceTerms'] ?? '', ['fixed', 'negotiable'], true),
            'declaredValue' => $positive('declaredValue'),
            'terms' => $filled('incoterm'),
            'paymentTerms' => $filled('seaPaymentTerms') || $positive('paymentDueDays') || $true('paymentDeferred'),
            'documentType' => $filled('blType'),
            // Both ends of the range are required, not just one - a range half-answered with only
            // a minimum must keep this step pending rather than letting the server's own next-step
            // marker silently jump ahead while a reply is still mid-way through asking for the
            // maximum (the user can still explicitly skip the rest via [[LENA_SKIP:temperature]]).
            'temperature' => ($draft['temperatureMin'] ?? null) !== null && ($draft['temperatureMax'] ?? null) !== null,
            'requirements' => $true('requiresAdr') || $true('requiresTailLift') || $true('tollRoadsIncluded') || $true('ferryIncluded') || $true('cmrRequired') || $true('palletExchangeRequired') || $true('customsRequired') || $true('insuranceRequired') || $true('certificationRequired') || $true('inspectionServicesRequired') || $true('isUrgent') || $true('requiresTracking'),
            'contact' => $any(['contactName', 'contactPhone', 'contactMobile', 'contactFax', 'contactEmail']),
            'supplier' => $any(['supplierName', 'supplierEmail', 'supplierPhone', 'supplierMobile', 'supplierFax']),
            'visibility' => $filled('closedFreightExchange') || $true('publishToAllAfterMinutes'),
            'comments' => $any(['internalComments', 'externalComments', 'additionalInfo']),
            'notes' => $filled('notes') || $filled('bookingReference') || ! empty($draft['customFields']),
            default => false,
        };
    }
}
