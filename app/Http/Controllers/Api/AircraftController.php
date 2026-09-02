<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AircraftController extends Controller
{
    private const SEARCH_RADIUS_NM = 250;
    private const GRID_STEP_DEGREES = 5.5;

    public function index(Request $request): JsonResponse
    {
        $bounds = $request->validate([
            'south' => ['required', 'numeric', 'between:-90,90'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'north' => ['required', 'numeric', 'between:-90,90', 'gt:south'],
            'east' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $south = (float) $bounds['south'];
        $west = (float) $bounds['west'];
        $north = (float) $bounds['north'];
        $east = (float) $bounds['east'];
        $tiles = $this->tilesForBounds($south, $west, $north, $east);
        $payloads = [];
        $missing = [];

        foreach ($tiles as $tile) {
            $cached = Cache::get($tile['key']);
            if (is_array($cached)) $payloads[] = $cached;
            else $missing[] = $tile;
        }

        // Small parallel batches cover the full viewport while keeping each provider request bounded.
        foreach (array_chunk($missing, 6) as $chunk) {
            try {
                $responses = Http::pool(function (Pool $pool) use ($chunk): array {
                    $requests = [];
                    foreach ($chunk as $index => $tile) {
                        $requests[] = $pool->as((string) $index)
                            ->acceptJson()
                            ->timeout(15)
                            ->get($this->tileUrl($tile['lat'], $tile['lon']));
                    }
                    return $requests;
                });

                foreach ($chunk as $index => $tile) {
                    $response = $responses[(string) $index] ?? null;
                    if (! $response || ! $response->successful()) continue;
                    $payload = $response->json();
                    if (! is_array($payload)) continue;
                    Cache::put($tile['key'], $payload, now()->addSeconds(12));
                    $payloads[] = $payload;
                }
            } catch (\Throwable $error) {
                report($error);
            }
        }

        if ($payloads === []) {
            return response()->json([
                'message' => 'Live aircraft data is temporarily unavailable.',
                'data' => [], 'meta' => [], 'errors' => [],
            ], 502);
        }

        $aircraft = collect($payloads)
            ->flatMap(fn (array $payload) => $payload['ac'] ?? [])
            ->filter(fn ($row) => is_array($row) && is_numeric($row['lat'] ?? null) && is_numeric($row['lon'] ?? null))
            ->filter(fn (array $row) => $this->insideBounds((float) $row['lat'], (float) $row['lon'], $south, $west, $north, $east))
            ->sortBy(fn (array $row) => (float) ($row['seen'] ?? PHP_FLOAT_MAX))
            ->unique(fn (array $row) => (string) ($row['hex'] ?? sprintf('%0.5f:%0.5f', $row['lat'], $row['lon'])))
            ->values();

        return response()->json([
            'message' => 'Live aircraft retrieved.',
            'data' => $aircraft,
            'meta' => ['count' => $aircraft->count(), 'tiles' => count($tiles)],
            'errors' => [],
        ]);
    }

    /** @return array<int, array{lat: float, lon: float, key: string}> */
    private function tilesForBounds(float $south, float $west, float $north, float $east): array
    {
        $tiles = [];
        $ranges = $east >= $west ? [[$west, $east]] : [[$west, 180.0], [-180.0, $east]];
        $latStart = (int) floor($south / self::GRID_STEP_DEGREES);
        $latEnd = (int) ceil($north / self::GRID_STEP_DEGREES);

        for ($latIndex = $latStart; $latIndex <= $latEnd; $latIndex++) {
            $lat = max(-87.25, min(87.25, $latIndex * self::GRID_STEP_DEGREES));
            $lonStep = min(60.0, self::GRID_STEP_DEGREES / max(0.15, cos(deg2rad($lat))));
            foreach ($ranges as [$rangeWest, $rangeEast]) {
                for ($lonIndex = (int) floor($rangeWest / $lonStep); $lonIndex <= (int) ceil($rangeEast / $lonStep); $lonIndex++) {
                    $lon = max(-180.0, min(180.0, $lonIndex * $lonStep));
                    $key = sprintf('aircraft-tile:%0.2f:%0.2f:%d', $lat, $lon, self::SEARCH_RADIUS_NM);
                    $tiles[$key] = ['lat' => $lat, 'lon' => $lon, 'key' => $key];
                }
            }
        }
        return array_values($tiles);
    }

    private function insideBounds(float $lat, float $lon, float $south, float $west, float $north, float $east): bool
    {
        if ($lat < $south || $lat > $north) return false;
        return $east >= $west ? $lon >= $west && $lon <= $east : $lon >= $west || $lon <= $east;
    }

    private function tileUrl(float $lat, float $lon): string
    {
        return sprintf('https://api.adsb.lol/v2/lat/%0.4f/lon/%0.4f/dist/%d', $lat, $lon, self::SEARCH_RADIUS_NM);
    }
}
