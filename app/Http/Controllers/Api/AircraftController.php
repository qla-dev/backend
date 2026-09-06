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

        if (! $answered) {
            throw new RuntimeException('The live aircraft search response was unavailable.');
        }

        return [];
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
