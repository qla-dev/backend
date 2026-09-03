<?php

namespace App\Services;

use App\Services\Contracts\VesselSnapshotClient;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenWatersVesselClient implements VesselSnapshotClient
{
    public function capture(
        float $south,
        float $west,
        float $north,
        float $east,
        array $mmsis = [],
    ): array {
        $mmsis = array_values(array_unique(array_filter(array_map(
            static fn (mixed $mmsi): string => trim((string) $mmsi),
            $mmsis,
        ), static fn (string $mmsi): bool => preg_match('/^\d{9}$/', $mmsi) === 1)));

        if ($mmsis !== []) {
            return $this->request(['mmsi' => implode(',', array_slice($mmsis, 0, 50))]);
        }

        $boxes = $east >= $west
            ? [[$south, $west, $north, $east]]
            : [[$south, $west, $north, 180], [$south, -180, $north, $east]];
        $vessels = [];
        foreach ($boxes as $box) {
            foreach ($this->request(['bbox' => implode(',', $box)]) as $vessel) {
                $vessels[$vessel['mmsi']] = array_merge($vessels[$vessel['mmsi']] ?? [], $vessel);
            }
        }

        return array_values($vessels);
    }

    /** @return array<int, array<string, mixed>> */
    private function request(array $query): array
    {
        $response = $this->http()->get('/v1/vessels', $query);
        if (! $response->successful()) {
            throw new RuntimeException("Open Waters vessel API returned HTTP {$response->status()}.");
        }

        $features = $response->json('features');
        if (! is_array($features)) {
            throw new RuntimeException('Open Waters vessel API returned an invalid response.');
        }

        return array_values(array_filter(array_map(fn (mixed $feature): ?array => $this->normalize($feature), $features)));
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.open_waters.url'), '/'))
            ->acceptJson()
            ->withUserAgent('SmartFreight/1.0')
            ->connectTimeout(3)
            ->timeout(6)
            ->retry(1, 150);
    }

    private function normalize(mixed $feature): ?array
    {
        if (! is_array($feature)) {
            return null;
        }
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $geometry = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : [];
        $coordinates = is_array($geometry['coordinates'] ?? null) ? $geometry['coordinates'] : [];
        $mmsi = trim((string) ($properties['mmsi'] ?? $feature['id'] ?? ''));
        if (preg_match('/^\d{9}$/', $mmsi) !== 1 || ! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return null;
        }

        $row = [
            'mmsi' => $mmsi,
            'lat' => (float) $coordinates[1],
            'lon' => (float) $coordinates[0],
            'updated_at' => (string) ($properties['seen'] ?? now()->toIso8601String()),
            'provider' => 'open_waters',
            'source' => trim((string) ($properties['source'] ?? '')),
        ];
        foreach (['name' => 'name', 'callsign' => 'callsign', 'destination' => 'destination'] as $source => $target) {
            if (isset($properties[$source]) && trim((string) $properties[$source]) !== '') {
                $row[$target] = trim((string) $properties[$source]);
            }
        }
        foreach (['sog' => 'speed', 'cog' => 'course', 'heading' => 'heading', 'nav_status' => 'navigation_status', 'type' => 'ship_type'] as $source => $target) {
            if (isset($properties[$source]) && is_numeric($properties[$source])) {
                $row[$target] = (float) $properties[$source];
            }
        }

        return $row;
    }
}
