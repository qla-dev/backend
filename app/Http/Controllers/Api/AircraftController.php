<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AircraftController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lon' => ['required', 'numeric', 'between:-180,180'],
            'dist' => ['sometimes', 'integer', 'min:1', 'max:250'],
        ]);

        $lat = round((float) $data['lat'], 2);
        $lon = round((float) $data['lon'], 2);
        $dist = (int) ($data['dist'] ?? 250);
        $cacheKey = sprintf('adsb-lol:%0.2f:%0.2f:%d', $lat, $lon, $dist);

        try {
            $payload = Cache::remember($cacheKey, now()->addSeconds(8), function () use ($lat, $lon, $dist): array {
                return Http::acceptJson()
                    ->timeout(12)
                    ->retry(2, 250)
                    ->get("https://api.adsb.lol/v2/lat/{$lat}/lon/{$lon}/dist/{$dist}")
                    ->throw()
                    ->json();
            });
        } catch (\Throwable $error) {
            report($error);

            return response()->json([
                'message' => 'Live aircraft data is temporarily unavailable.',
                'data' => [],
                'meta' => ['attribution' => 'ADSB.lol', 'source_url' => 'https://adsb.lol/'],
                'errors' => [],
            ], 502);
        }

        if (! is_array($payload)) {
            throw ValidationException::withMessages(['response' => 'The aircraft provider returned an invalid response.']);
        }

        $aircraft = collect($payload['ac'] ?? [])
            ->filter(fn ($row) => is_array($row) && is_numeric($row['lat'] ?? null) && is_numeric($row['lon'] ?? null))
            ->values();

        return response()->json([
            'message' => 'Live aircraft retrieved.',
            'data' => $aircraft,
            'meta' => [
                'count' => $aircraft->count(),
                'now' => $payload['now'] ?? null,
                'total' => $payload['total'] ?? $aircraft->count(),
                'attribution' => 'ADSB.lol',
                'source_url' => 'https://adsb.lol/',
            ],
            'errors' => [],
        ]);
    }
}
