<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\VesselSnapshotClient;
use App\Services\Contracts\VesselStreamClient;
use App\Support\VesselReference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VesselController extends Controller
{
    public function index(Request $request, VesselSnapshotClient $primary, VesselStreamClient $fallback): JsonResponse
    {
        $validated = $request->validate([
            'south' => ['required_without:search', 'numeric', 'between:-90,90'],
            'west' => ['required_without:search', 'numeric', 'between:-180,180'],
            'north' => ['required_without:search', 'numeric', 'between:-90,90'],
            'east' => ['required_without:search', 'numeric', 'between:-180,180'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search === '') {
            $request->validate(['south' => ['required'], 'north' => ['required', 'gt:south'], 'west' => ['required'], 'east' => ['required']]);
        }
        [$south, $west, $north, $east] = $search !== ''
            ? [-90.0, -180.0, 90.0, 180.0]
            : array_map('floatval', [$validated['south'], $validated['west'], $validated['north'], $validated['east']]);
        $searchedMmsi = preg_match('/^\d{9}$/', $search) === 1 ? $search : null;

        $primaryFailed = false;
        try {
            $updates = $primary->capture(
                $south,
                $west,
                $north,
                $east,
                $searchedMmsi !== null ? [$searchedMmsi] : [],
                $searchedMmsi === null ? $search : '',
            );
        } catch (\Throwable $error) {
            report($error);
            $primaryFailed = true;
            $updates = [];
        }

        $stored = Cache::get('live-vessels', []);
        $stored = is_array($stored) ? $stored : [];
        $missingNames = array_values(array_map(
            fn (array $row): string => (string) $row['mmsi'],
            array_filter($updates, fn (array $row): bool => isset($row['mmsi'])
                && trim((string) ($row['name'] ?? $stored[$row['mmsi']]['name'] ?? '')) === ''),
        ));
        $textSearch = $search !== '' && $searchedMmsi === null;

        if ($updates === [] || $missingNames !== [] || $textSearch) {
            try {
                // Enrich snapshots with static AIS data; text searches need global coverage.
                $enrichment = $searchedMmsi !== null
                    ? $fallback->capture(-90, -180, 90, 180, 8.0, [$searchedMmsi])
                    : ($textSearch
                        ? $fallback->capture(-90, -180, 90, 180, 8.0)
                        : $fallback->capture($south, $west, $north, $east));
                $updates = array_merge($updates, $enrichment);
            } catch (\Throwable $error) {
                report($error);
                if ($primaryFailed) {
                    return response()->json([
                        'message' => 'Live vessel providers are currently unavailable.', 'data' => [],
                        'meta' => ['configured' => true], 'errors' => [],
                    ], 502);
                }
            }
        }

        foreach ($updates as $update) {
            $key = (string) ($update['mmsi'] ?? '');
            if ($key !== '') {
                $stored[$key] = $this->mergeVessel($stored[$key] ?? [], $update);
            }
        }
        $cutoff = now()->subMinutes(30);
        $stored = array_filter($stored, fn (array $row) => isset($row['updated_at']) && $cutoff->lessThanOrEqualTo($row['updated_at']));
        Cache::put('live-vessels', $stored, now()->addMinutes(20));

        $vessels = collect($stored)->filter(function (array $row) use ($south, $west, $north, $east, $search): bool {
            if (! isset($row['lat'], $row['lon'])) {
                return false;
            }
            if ($search !== '') {
                $haystack = implode(' ', [
                    $row['name'] ?? '',
                    $row['mmsi'] ?? '',
                    $row['callsign'] ?? '',
                    $row['destination'] ?? '',
                ]);

                return str_contains(mb_strtolower($haystack), mb_strtolower($search));
            }
            $lat = (float) $row['lat'];
            $lon = (float) $row['lon'];
            if ($lat < $south || $lat > $north) {
                return false;
            }

            return $east >= $west ? $lon >= $west && $lon <= $east : $lon >= $west || $lon <= $east;
        })->values();

        return response()->json([
            'message' => 'Live vessels retrieved.', 'data' => $vessels,
            'meta' => [
                'count' => $vessels->count(),
                'configured' => true,
                'global_search' => $search !== '',
                'primary_provider' => 'open_waters',
                'fallback_provider' => 'aisstream',
            ],
            'errors' => [],
        ]);
    }

    /**
     * Everything the detail view shows about one vessel. AIS sends the flag
     * state, ship type and navigation status as bare ITU numbers, so they are
     * decoded here rather than in the interface.
     */
    public function details(string $mmsi, VesselStreamClient $fallback): JsonResponse
    {
        if (preg_match('/^\d{9}$/', $mmsi) !== 1) {
            return response()->json([
                'message' => 'The vessel identifier is invalid.',
                'data' => null, 'meta' => [], 'errors' => [],
            ], 422);
        }

        $stored = Cache::get('live-vessels', []);
        $row = is_array($stored) ? ($stored[$mmsi] ?? null) : null;

        if (! is_array($row) || trim((string) ($row['name'] ?? '')) === '') {
            // Position-only snapshots also need static vessel details.
            try {
                foreach ($fallback->capture(-90, -180, 90, 180, 8.0, [$mmsi]) as $update) {
                    if ((string) ($update['mmsi'] ?? '') === $mmsi) {
                        $row = $this->mergeVessel($row ?? [], $update);
                    }
                }
            } catch (\Throwable $error) {
                report($error);

                if (! is_array($row)) {
                    return response()->json([
                        'message' => 'Vessel details are temporarily unavailable.',
                        'data' => null, 'meta' => [], 'errors' => [],
                    ], 502);
                }
            }
        }

        if (! is_array($row)) {
            return response()->json([
                'message' => 'This vessel is not reporting right now.',
                'data' => null, 'meta' => [], 'errors' => [],
            ], 404);
        }

        $latitude = is_numeric($row['lat'] ?? null) ? (float) $row['lat'] : null;
        $longitude = is_numeric($row['lon'] ?? null) ? (float) $row['lon'] : null;

        return response()->json([
            'message' => 'Vessel details retrieved.',
            'data' => [
                'mmsi' => $mmsi,
                'name' => trim((string) ($row['name'] ?? '')) ?: null,
                'callsign' => trim((string) ($row['callsign'] ?? '')) ?: null,
                'country' => VesselReference::flagState($mmsi),
                'ship_type' => VesselReference::shipType($row['ship_type'] ?? null),
                'ship_type_code' => is_numeric($row['ship_type'] ?? null) ? (int) $row['ship_type'] : null,
                'navigation_status' => VesselReference::navigationStatus($row['navigation_status'] ?? null),
                'destination' => trim((string) ($row['destination'] ?? '')) ?: null,
                'position' => $latitude === null || $longitude === null ? null : ['lat' => $latitude, 'lon' => $longitude],
                'speed' => is_numeric($row['speed'] ?? null) ? (float) $row['speed'] : null,
                'course' => is_numeric($row['course'] ?? null) ? (float) $row['course'] : null,
                'heading' => is_numeric($row['heading'] ?? null) ? (float) $row['heading'] : null,
                'updated_at' => $row['updated_at'] ?? null,
                'provider' => $row['provider'] ?? null,
            ],
            'meta' => [], 'errors' => [],
        ]);
    }

    private function mergeVessel(array $current, array $update): array
    {
        foreach (['name', 'callsign', 'destination'] as $field) {
            if (trim((string) ($update[$field] ?? '')) === '') {
                unset($update[$field]);
            }
        }

        return array_merge($current, $update);
    }
}
