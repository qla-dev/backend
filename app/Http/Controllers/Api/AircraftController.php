<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AircraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
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
        } catch (\Throwable $error) {
            report($error);
            return response()->json([
                'message' => 'Live aircraft data is temporarily unavailable.',
                'data' => [], 'meta' => [], 'errors' => [],
            ], 502);
        }

        $aircraft = collect($payload['aircraft'] ?? $payload['ac'] ?? [])
            ->filter(fn ($row) => is_array($row) && is_numeric($row['lat'] ?? null) && is_numeric($row['lon'] ?? null))
            ->filter(fn (array $row) => $this->insideBounds((float) $row['lat'], (float) $row['lon'], $south, $west, $north, $east))
            ->sortBy(fn (array $row) => (float) ($row['seen'] ?? PHP_FLOAT_MAX))
            ->unique(fn (array $row) => (string) ($row['hex'] ?? sprintf('%0.5f:%0.5f', $row['lat'], $row['lon'])))
            ->values();

        return response()->json([
            'message' => 'Live aircraft retrieved.', 'data' => $aircraft,
            'meta' => ['count' => $aircraft->count()], 'errors' => [],
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
