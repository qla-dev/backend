<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AircraftController extends Controller
{
    private const API_BASE = 'https://api.adsb.lol';

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $search = trim($validated['search'] ?? '');

        if ($search !== '') {
            return $this->searchResponse($search);
        }

        [$south, $west, $north, $east] = $this->resolveBounds($request);
        $cacheKey = sprintf('aircraft-viewport:%0.2f:%0.2f:%0.2f:%0.2f', $south, $west, $north, $east);

        try {
            $payload = Cache::remember($cacheKey, now()->addSeconds(6), function () use ($south, $west, $north, $east): array {
                $cookies = new CookieJar();
                $client = Http::withOptions(['cookies' => $cookies])
                    ->withHeaders([
                        'Accept' => 'application/json',
                        'Referer' => 'https://adsb.lol/',
                        'X-Requested-With' => 'XMLHttpRequest',
                    ])
                    ->timeout(20);

                // The globe endpoint assigns its backend shard cookie on the landing request.
                $client->get('https://adsb.lol/')->throw();
                $box = implode(',', [$south, $north, $west, $east]);
                $response = null;
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $response = $client->get("https://adsb.lol/re-api/?json&box={$box}");
                    if ($response->successful() && is_array($response->json())) break;
                    usleep(200_000);
                }
                if (! $response || ! $response->successful() || ! is_array($response->json())) {
                    throw new RuntimeException('The live aircraft viewport response was unavailable.');
                }
                return $response->json();
            });
        } catch (Throwable $error) {
            report($error);
            return response()->json([
                'message' => 'Live aircraft data is temporarily unavailable.',
                'data' => [], 'meta' => [], 'errors' => [],
            ], 502);
        }

        $aircraft = $this->normalize($payload['aircraft'] ?? $payload['ac'] ?? [])
            ->filter(fn (array $row) => $this->insideBounds((float) $row['lat'], (float) $row['lon'], $south, $west, $north, $east))
            ->values();

        return response()->json([
            'message' => 'Live aircraft retrieved.', 'data' => $aircraft,
            'meta' => ['count' => $aircraft->count()], 'errors' => [],
        ]);
    }

    /**
     * Searching the globe viewport only ever saw the shard the map happened to be
     * served, so flights outside it came back as "not found". The documented
     * adsb.lol lookups (https://api.adsb.lol/docs) resolve an identifier against
     * the whole network instead.
     */
    private function searchResponse(string $search): JsonResponse
    {
        try {
            $aircraft = Cache::remember(
                'aircraft-search:' . strtoupper($search),
                now()->addSeconds(6),
                fn (): array => $this->lookup($search),
            );
        } catch (Throwable $error) {
            report($error);
            return response()->json([
                'message' => 'Live aircraft data is temporarily unavailable.',
                'data' => [], 'meta' => [], 'errors' => [],
            ], 502);
        }

        return response()->json([
            'message' => $aircraft === []
                ? 'No aircraft matching this search is transmitting right now.'
                : 'Live aircraft retrieved.',
            'data' => $aircraft,
            'meta' => ['count' => count($aircraft), 'search' => $search],
            'errors' => [],
        ]);
    }

    /**
     * The candidates are tried one at a time, most likely first, and the search
     * stops on the first identifier that resolves. The documented API allows
     * roughly one request a second, so it only ever sees the leading candidate;
     * the globe lookup the map already uses carries any remaining attempt.
     *
     * @return array<int, array<string, mixed>>
     */
    private function lookup(string $search): array
    {
        $candidates = $this->searchCandidates($search);
        if ($candidates === []) {
            return [];
        }

        $answered = false;
        foreach ($candidates as $index => $candidate) {
            [$kind, $value] = $candidate;
            $response = $index === 0
                ? $this->documentedLookup($kind, $value)
                : $this->globeLookup($kind, $value);
            if ($response === null) {
                continue;
            }
            $answered = true;
            if ($response !== []) {
                return $this->normalize($response)->values()->all();
            }
        }

        // Nothing is transmitting, so fall back to the registry the map itself
        // reads. An aircraft that is parked still has to be bookable.
        $registered = $this->registryLookup($search);
        if ($registered !== null) {
            return [$registered];
        }

        if (! $answered) {
            throw new RuntimeException('The live aircraft search response was unavailable.');
        }

        return [];
    }

    /**
     * The registry is keyed by ICAO hex, so a registration is resolved by
     * reading the shards its country prefix is allocated. Shards are static
     * files, hence the long cache.
     *
     * @return array<string, mixed>|null
     */
    private function registryLookup(string $search): ?array
    {
        $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper($search)));
        if ($normalized === '') {
            return null;
        }

        $hex = preg_match('/^[0-9A-F]{6}$/', $normalized)
            ? $normalized
            : $this->registryHexForRegistration($normalized);
        if ($hex === null) {
            return null;
        }

        $entry = $this->registryEntry($hex);
        if ($entry === null) {
            return null;
        }

        return $this->registryRow($hex, $entry);
    }

    /** @return string|null  the ICAO hex the registration is assigned */
    private function registryHexForRegistration(string $normalized): ?string
    {
        $queue = $this->registryShards($normalized);
        // Big blocks such as the US branch into child shards, which hold most of
        // the fleet. The cap keeps an unlucky search from walking the registry.
        for ($read = 0; $queue !== [] && $read < 24; $read++) {
            $shard = array_shift($queue);
            foreach ($this->registryFile($shard) as $suffix => $entry) {
                if ($suffix === 'children') {
                    foreach ((array) $entry as $child) {
                        $queue[] = (string) $child;
                    }

                    continue;
                }
                if (! is_array($entry)) {
                    continue;
                }
                $registration = strtoupper(preg_replace('/[^A-Z0-9]/', '', strtoupper((string) ($entry[0] ?? ''))));
                if ($registration !== '' && $registration === $normalized) {
                    return $shard . $suffix;
                }
            }
        }

        return null;
    }

    /**
     * ICAO allocates each country a hex block, so a registration prefix only
     * ever needs one or two shards. An unlisted prefix reads nothing rather
     * than sweeping the whole registry, which keeps a callsign search cheap.
     *
     * @return array<int, string>
     */
    private function registryShards(string $normalized): array
    {
        $blocks = [
            'B' => ['7', '8'], 'N' => ['A'], 'D' => ['3'], 'F' => ['3'], 'I' => ['3'],
            'G' => ['4'], 'EI' => ['4'], 'OE' => ['4'], 'OO' => ['4'], 'OK' => ['4'],
            'OY' => ['4'], 'OH' => ['4'], 'SE' => ['4'], 'LN' => ['4'], 'HB' => ['4'],
            'OM' => ['4'], 'OP' => ['4'], 'SP' => ['4'], 'OL' => ['4'], 'TC' => ['4'],
            'OD' => ['7'], 'M' => ['4'], 'CS' => ['4'], 'EC' => ['3'], 'ZK' => ['C'],
            'VH' => ['7'], 'JA' => ['8'], 'HL' => ['7'], 'VT' => ['8'], 'PK' => ['8'],
            'A6' => ['8'], 'A7' => ['8'], 'HZ' => ['7'], '9V' => ['7'], '9M' => ['7'],
            'HS' => ['8'], 'RP' => ['7'], '4X' => ['7'], 'RA' => ['1'], 'UR' => ['5'],
            'ZS' => ['0'], 'SU' => ['0'], 'XA' => ['0'], 'XB' => ['0'], 'XC' => ['0'],
            'CC' => ['E'], 'LV' => ['E'], 'PR' => ['E'], 'PS' => ['E'], 'PP' => ['E'],
            'PT' => ['E'], 'CX' => ['4'], 'T7' => ['5'], '9H' => ['4'], 'E7' => ['5'],
            '9A' => ['5'], 'S5' => ['5'], 'YU' => ['5'], 'LZ' => ['4'], 'YR' => ['4'],
            'LY' => ['5'], 'ES' => ['5'], 'YL' => ['5'], 'Z3' => ['5'], 'ZA' => ['5'],
        ];

        foreach ([2, 1] as $length) {
            $prefix = substr($normalized, 0, $length);
            if (isset($blocks[$prefix])) {
                return $blocks[$prefix];
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function registryFile(string $shard): array
    {
        return Cache::remember(
            "aircraft-registry-shard:{$shard}",
            now()->addHours(12),
            function () use ($shard): array {
                $response = Http::withHeaders(['Accept' => 'application/json'])
                    ->timeout(30)
                    ->get('https://adsb.lol/db2/' . $shard . '.js');

                return $response->successful() && is_array($response->json()) ? $response->json() : [];
            },
        );
    }

    /**
     * The registry is a trie: a shard either holds the entry or names the child
     * shard that continues the hex.
     *
     * @return array<int, mixed>|null
     */
    private function registryEntry(string $hex): ?array
    {
        for ($length = 1; $length <= strlen($hex); $length++) {
            $shard = substr($hex, 0, $length);
            $file = $this->registryFile($shard);
            if ($file === []) {
                return null;
            }
            $suffix = substr($hex, $length);
            if (isset($file[$suffix]) && is_array($file[$suffix])) {
                return $file[$suffix];
            }
            $child = $shard . substr($suffix, 0, 1);
            if (! in_array($child, (array) ($file['children'] ?? []), true)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Shapes a registry hit like a live one so the map and the booking pickers
     * treat both the same, and pins the last position the aircraft reported.
     *
     * @param  array<int, mixed>  $entry
     * @return array<string, mixed>
     */
    private function registryRow(string $hex, array $entry): array
    {
        $row = [
            'hex' => strtolower($hex),
            'r' => trim((string) ($entry[0] ?? '')) ?: null,
            't' => trim((string) ($entry[1] ?? '')) ?: null,
            'desc' => trim((string) ($entry[3] ?? '')) ?: null,
            'flight' => null,
            'position_source' => 'registry',
            'seen_at' => null,
            'lat' => null,
            'lon' => null,
        ];

        $fix = $this->lastKnownPosition(strtolower($hex));
        if ($fix !== null) {
            // The registry names the aircraft; the trace only fills the gaps.
            $row = array_merge($row, array_filter($fix, fn ($value) => $value !== null), [
                'position_source' => 'last_seen',
            ]);
        }

        return $row;
    }

    /**
     * The trace files keep flying after an aircraft leaves the live feed, which
     * is how the map still draws it. They are the last position we can offer.
     *
     * @return array<string, mixed>|null
     */
    private function lastKnownPosition(string $hex): ?array
    {
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Referer' => 'https://adsb.lol/',
        ])->timeout(15)->get(sprintf(
            'https://adsb.lol/data/traces/%s/trace_recent_%s.json',
            substr($hex, -2),
            $hex,
        ));

        $payload = $response->successful() && is_array($response->json()) ? $response->json() : null;
        if ($payload === null) {
            return null;
        }

        $base = (float) ($payload['timestamp'] ?? 0);
        $latest = null;
        foreach ($payload['trace'] ?? [] as $point) {
            if (is_array($point) && is_numeric($point[1] ?? null) && is_numeric($point[2] ?? null)) {
                $latest = $point;
            }
        }
        if ($latest === null) {
            return null;
        }

        return [
            'lat' => (float) $latest[1],
            'lon' => (float) $latest[2],
            'alt_baro' => is_numeric($latest[3] ?? null) ? (float) $latest[3] : ($latest[3] ?? null),
            'seen_at' => (int) round($base + (float) $latest[0]),
            'r' => trim((string) ($payload['r'] ?? '')) ?: null,
            't' => trim((string) ($payload['t'] ?? '')) ?: null,
            'desc' => trim((string) ($payload['desc'] ?? '')) ?: null,
        ];
    }

    /**
     * @return array<int, mixed>|null  null when the endpoint could not be reached
     */
    private function documentedLookup(string $kind, string $value): ?array
    {
        $response = Http::withHeaders(['Accept' => 'application/json'])
            ->timeout(12)
            ->get(self::API_BASE . '/v2/' . $kind . '/' . rawurlencode($value));

        return $this->rows($response, 'ac');
    }

    /**
     * @return array<int, mixed>|null  null when the endpoint could not be reached
     */
    private function globeLookup(string $kind, string $value): ?array
    {
        // The flag has to stay bare: "?json=" turns the response back into HTML.
        $url = sprintf(
            'https://adsb.lol/re-api/?json&find_%s=%s',
            $kind,
            rawurlencode($value),
        );
        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Referer' => 'https://adsb.lol/',
        ])->timeout(12)->get($url);

        return $this->rows($response, 'aircraft');
    }

    /** @return array<int, mixed>|null */
    private function rows(Response $response, string $key): ?array
    {
        if (! $response->successful()) {
            return null;
        }
        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        return array_values(array_filter((array) ($body[$key] ?? []), 'is_array'));
    }

    /**
     * Each lookup matches exactly, so the term is turned into the identifiers it
     * could plausibly be, ordered by how likely each one is.
     *
     * @return array<int, array{string, string}>
     */
    private function searchCandidates(string $search): array
    {
        $upper = strtoupper($search);
        $alnum = preg_replace('/[^A-Z0-9]/', '', $upper);
        $registration = trim(preg_replace('/[^A-Z0-9-]/', '', $upper), '-');
        if ($alnum === '') {
            return [];
        }

        $candidates = [];
        if (preg_match('/^[0-9A-F]{6}$/', $alnum)) {
            $candidates[] = ['hex', $alnum];
        }
        if (preg_match('/^[0-7]{4}$/', $alnum)) {
            $candidates[] = ['squawk', $alnum];
        }

        // A hyphen only ever appears in a registration, never in a callsign.
        $registrations = array_map(
            fn (string $variant): array => ['reg', $variant],
            $this->registrationVariants($registration, $alnum),
        );
        if (str_contains($registration, '-')) {
            $candidates = array_merge($candidates, $registrations, [['callsign', $alnum]]);
        } else {
            $candidates = array_merge($candidates, [['callsign', $alnum]], $registrations);
        }

        if (preg_match('/^[A-Z][A-Z0-9]{2,3}$/', $alnum)) {
            $candidates[] = ['type', $alnum];
        }

        return array_values(array_intersect_key(
            $candidates,
            array_unique(array_map(fn (array $candidate) => implode(':', $candidate), $candidates)),
        ));
    }

    /**
     * Registrations are matched with their hyphen, so "B-226S" never resolves as
     * "B226S". Rebuild the one- and two-character country prefixes that cover
     * civil registrations when the operator leaves the hyphen out.
     *
     * @return array<int, string>
     */
    private function registrationVariants(string $registration, string $alnum): array
    {
        $variants = $registration === '' ? [] : [$registration];
        if ($alnum !== '' && ! str_contains($registration, '-')) {
            foreach ([1, 2] as $prefixLength) {
                if (strlen($alnum) > $prefixLength + 1) {
                    $variants[] = substr($alnum, 0, $prefixLength) . '-' . substr($alnum, $prefixLength);
                }
            }
        }

        return array_values(array_unique($variants));
    }

    /**
     * @param  iterable<mixed>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function normalize(iterable $rows): Collection
    {
        return collect($rows)
            ->filter(fn ($row) => is_array($row) && is_numeric($row['lat'] ?? null) && is_numeric($row['lon'] ?? null))
            ->sortBy(fn (array $row) => (float) ($row['seen'] ?? PHP_FLOAT_MAX))
            ->unique(fn (array $row) => (string) ($row['hex'] ?? sprintf('%0.5f:%0.5f', $row['lat'], $row['lon'])));
    }

    public function trace(string $hex): JsonResponse
    {
        $hex = strtolower(ltrim(trim($hex), '~'));
        if (! preg_match('/^[0-9a-f]{6}$/', $hex)) {
            return response()->json([
                'message' => 'The aircraft identifier is invalid.',
                'data' => ['segments' => []], 'meta' => [], 'errors' => [],
            ], 422);
        }

        try {
            $segments = Cache::remember("aircraft-trace:{$hex}", now()->addSeconds(30), function () use ($hex): array {
                $client = Http::withHeaders([
                    'Accept' => 'application/json',
                    'Referer' => 'https://adsb.lol/',
                ])->timeout(15)->retry(2, 200, null, false);
                $points = [];
                $successfulResponses = 0;

                foreach (['full', 'recent'] as $scope) {
                    $url = sprintf(
                        'https://adsb.lol/data/traces/%s/trace_%s_%s.json',
                        substr($hex, -2),
                        $scope,
                        $hex,
                    );
                    $response = $client->get($url);
                    if (! $response->successful() || ! is_array($response->json())) {
                        continue;
                    }
                    $successfulResponses++;
                    $payload = $response->json();
                    $baseTimestamp = (float) ($payload['timestamp'] ?? 0);

                    foreach ($payload['trace'] ?? [] as $row) {
                        if (! is_array($row) || ! is_numeric($row[1] ?? null) || ! is_numeric($row[2] ?? null)) {
                            continue;
                        }
                        $timestamp = $baseTimestamp + (float) ($row[0] ?? 0);
                        $key = sprintf('%0.2f:%0.5f:%0.5f', $timestamp, (float) $row[1], (float) $row[2]);
                        $points[$key] = [
                            'lat' => (float) $row[1],
                            'lon' => (float) $row[2],
                            'altitude' => is_numeric($row[3] ?? null) ? (float) $row[3] : null,
                            'timestamp' => $timestamp,
                            'on_ground' => ($row[3] ?? null) === 'ground',
                        ];
                    }
                }

                if ($successfulResponses === 0) {
                    throw new RuntimeException('The aircraft path is temporarily unavailable.');
                }

                usort($points, fn (array $left, array $right) => $left['timestamp'] <=> $right['timestamp']);
                $lastAirborneIndex = null;
                for ($index = count($points) - 1; $index >= 0; $index--) {
                    if (! $points[$index]['on_ground']) {
                        $lastAirborneIndex = $index;
                        break;
                    }
                }
                if ($lastAirborneIndex === null) {
                    return [];
                }

                // Only retain the most recent flight. The nearest ground report before
                // the last airborne point is its departure boundary; older legs are discarded.
                $flightStartIndex = 0;
                for ($index = $lastAirborneIndex - 1; $index >= 0; $index--) {
                    if ($points[$index]['on_ground']) {
                        $flightStartIndex = $index;
                        break;
                    }
                }
                $flightEndIndex = min(count($points) - 1, $lastAirborneIndex + 1);
                $points = array_slice($points, $flightStartIndex, $flightEndIndex - $flightStartIndex + 1);

                $segments = [];
                $segment = [];
                $previous = null;
                foreach ($points as $point) {
                    unset($point['on_ground']);
                    $isGap = $previous !== null && abs($point['lon'] - $previous['lon']) > 180;
                    if ($isGap && count($segment) > 1) {
                        $segments[] = $segment;
                        $segment = [];
                    }
                    $segment[] = $point;
                    $previous = $point;
                }
                if (count($segment) > 1) {
                    $segments[] = $segment;
                }

                return $segments;
            });
        } catch (Throwable $error) {
            report($error);
            return response()->json([
                'message' => 'The aircraft path is temporarily unavailable.',
                'data' => ['segments' => []], 'meta' => [], 'errors' => [],
            ], 502);
        }

        return response()->json([
            'message' => 'Aircraft path retrieved.',
            'data' => ['segments' => $segments],
            'meta' => ['segments' => count($segments), 'points' => array_sum(array_map('count', $segments))],
            'errors' => [],
        ]);
    }

    /** @return array{float, float, float, float} */
    private function resolveBounds(Request $request): array
    {
        if ($request->has(['south', 'west', 'north', 'east'])) {
            $bounds = $request->validate([
                'south' => ['required', 'numeric', 'between:-90,90'],
                'west' => ['required', 'numeric', 'between:-180,180'],
                'north' => ['required', 'numeric', 'between:-90,90', 'gt:south'],
                'east' => ['required', 'numeric', 'between:-180,180'],
            ]);
            return [(float) $bounds['south'], (float) $bounds['west'], (float) $bounds['north'], (float) $bounds['east']];
        }

        // Keep already-open frontend bundles working during deployment.
        $point = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'dist' => ['sometimes', 'integer', 'min:1', 'max:250'],
        ]);
        $radiusDegrees = ((int) ($point['dist'] ?? 250)) / 60;
        $lat = (float) $point['lat']; $lon = (float) $point['lon'];
        $lonRadius = $radiusDegrees / max(0.15, cos(deg2rad($lat)));
        return [
            max(-90, $lat - $radiusDegrees), max(-180, $lon - $lonRadius),
            min(90, $lat + $radiusDegrees), min(180, $lon + $lonRadius),
        ];
    }

    private function insideBounds(float $lat, float $lon, float $south, float $west, float $north, float $east): bool
    {
        if ($lat < $south || $lat > $north) return false;
        return $east >= $west ? $lon >= $west && $lon <= $east : $lon >= $west || $lon <= $east;
    }
}
