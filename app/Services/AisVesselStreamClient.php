<?php

namespace App\Services;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use App\Services\Contracts\VesselStreamClient;
use RuntimeException;

use function Amp\Websocket\Client\connect;

class AisVesselStreamClient implements VesselStreamClient
{
    public function capture(
        float $south,
        float $west,
        float $north,
        float $east,
        float $seconds = 2.5,
        array $mmsis = [],
    ): array {
        $apiKey = trim((string) config('services.vessel_stream.api_key'));
        if ($apiKey === '') {
            throw new RuntimeException('Vessel live-data API key is not configured.');
        }

        $duration = max(0.5, min(8.0, $seconds));
        $connection = connect(
            (string) config('services.vessel_stream.url'),
            new TimeoutCancellation(5, 'The vessel live-data socket could not connect in time.'),
        );

        $boundingBoxes = $east >= $west
            ? [[[$south, $west], [$north, $east]]]
            : [[[$south, $west], [$north, 180]], [[$south, -180], [$north, $east]]];

        $subscription = [
            'APIKey' => $apiKey,
            'BoundingBoxes' => $boundingBoxes,
            'FilterMessageTypes' => [
                'PositionReport',
                'StandardClassBPositionReport',
                'ExtendedClassBPositionReport',
                'LongRangeAisBroadcastMessage',
                'ShipStaticData',
                'StaticDataReport',
            ],
        ];
        $mmsis = array_values(array_unique(array_filter(array_map(
            static fn (mixed $mmsi): string => trim((string) $mmsi),
            $mmsis,
        ), static fn (string $mmsi): bool => preg_match('/^\d{9}$/', $mmsi) === 1)));
        if ($mmsis !== []) {
            $subscription['FiltersShipMMSI'] = array_slice($mmsis, 0, 50);
        }

        $connection->sendText((string) json_encode($subscription, JSON_THROW_ON_ERROR));

        $vessels = [];
        $confirmed = false;
        $deadline = new TimeoutCancellation($duration, 'Vessel capture finished.');

        try {
            while ($message = $connection->receive($deadline)) {
                $payload = json_decode($message->buffer($deadline, 2 * 1024 * 1024), true, 64, JSON_THROW_ON_ERROR);
                if (! is_array($payload)) {
                    continue;
                }
                if (isset($payload['error']) || isset($payload['Error'])) {
                    throw new RuntimeException('The vessel live-data subscription was rejected.');
                }
                if (($payload['MessageType'] ?? '') === 'SubscriptionConfirmation') {
                    $confirmed = true;

                    continue;
                }

                $row = $this->normalize($payload, $vessels);
                if ($row !== null) {
                    $vessels[$row['mmsi']] = $row;
                }
            }
        } catch (CancelledException) {
            // A short, request-scoped capture intentionally ends at this deadline.
        } finally {
            $connection->close();
        }

        if (! $confirmed && $vessels === []) {
            throw new RuntimeException('The vessel live-data stream did not confirm the subscription.');
        }

        return array_values($vessels);
    }

    private function normalize(array $payload, array $vessels): ?array
    {
        $type = (string) ($payload['MessageType'] ?? '');
        $metadata = $payload['MetaData'] ?? $payload['Metadata'] ?? [];
        $metadata = is_array($metadata) ? $metadata : [];
        $body = $payload['Message'][$type] ?? [];
        $body = is_array($body) ? $body : [];

        // StaticDataReport wraps the actual fields in ReportA / ReportB.
        $details = array_merge(
            is_array($body['ReportA'] ?? null) ? $body['ReportA'] : [],
            is_array($body['ReportB'] ?? null) ? $body['ReportB'] : [],
            $body,
        );
        $mmsi = trim((string) ($metadata['MMSI'] ?? $details['UserID'] ?? ''));
        if ($mmsi === '') {
            return null;
        }

        $current = $vessels[$mmsi] ?? ['mmsi' => $mmsi];
        $row = array_merge($current, [
            'mmsi' => $mmsi,
            'name' => trim((string) ($metadata['ShipName'] ?? $details['Name'] ?? $current['name'] ?? '')),
            'updated_at' => now()->toIso8601String(),
        ]);

        $lat = $metadata['Latitude'] ?? $metadata['latitude'] ?? $details['Latitude'] ?? null;
        $lon = $metadata['Longitude'] ?? $metadata['longitude'] ?? $details['Longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lon)) {
            $row['lat'] = (float) $lat;
            $row['lon'] = (float) $lon;
        }

        foreach ([
            'Sog' => 'speed',
            'Cog' => 'course',
            'TrueHeading' => 'heading',
            'NavigationalStatus' => 'navigation_status',
            'Type' => 'ship_type',
            'ShipType' => 'ship_type',
        ] as $source => $target) {
            if (isset($details[$source]) && is_numeric($details[$source])) {
                $row[$target] = (float) $details[$source];
            }
        }

        foreach (['Destination' => 'destination', 'CallSign' => 'callsign'] as $source => $target) {
            if (isset($details[$source])) {
                $row[$target] = trim((string) $details[$source]);
            }
        }

        return $row;
    }
}
