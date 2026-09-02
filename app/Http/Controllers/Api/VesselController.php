<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Contracts\VesselStreamClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class VesselController extends Controller
{
    public function index(Request $request, VesselStreamClient $stream): JsonResponse
    {
        $bounds = $request->validate([
            'south' => ['required', 'numeric', 'between:-90,90'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'north' => ['required', 'numeric', 'between:-90,90', 'gt:south'],
            'east' => ['required', 'numeric', 'between:-180,180'],
        ]);
        [$south, $west, $north, $east] = array_map('floatval', [$bounds['south'], $bounds['west'], $bounds['north'], $bounds['east']]);

        try {
            $updates = $stream->capture($south, $west, $north, $east);
        } catch (\Throwable $error) {
            report($error);
            return response()->json([
                'message' => $error->getMessage(), 'data' => [],
                'meta' => ['configured' => (bool) config('services.vessel_stream.api_key')], 'errors' => [],
            ], config('services.vessel_stream.api_key') ? 502 : 503);
        }

        $stored = Cache::get('live-vessels', []);
        $stored = is_array($stored) ? $stored : [];
        foreach ($updates as $update) {
            $key = (string) ($update['mmsi'] ?? '');
            if ($key !== '') $stored[$key] = array_merge($stored[$key] ?? [], $update);
        }
        $cutoff = now()->subMinutes(15);
        $stored = array_filter($stored, fn (array $row) => isset($row['updated_at']) && $cutoff->lessThanOrEqualTo($row['updated_at']));
        Cache::put('live-vessels', $stored, now()->addMinutes(20));

        $visible = collect($stored)->filter(function (array $row) use ($south, $west, $north, $east): bool {
            if (! isset($row['lat'], $row['lon'])) return false;
            $lat = (float) $row['lat']; $lon = (float) $row['lon'];
            if ($lat < $south || $lat > $north) return false;
            return $east >= $west ? $lon >= $west && $lon <= $east : $lon >= $west || $lon <= $east;
        })->values();

        return response()->json([
            'message' => 'Live vessels retrieved.', 'data' => $visible,
            'meta' => ['count' => $visible->count(), 'configured' => true], 'errors' => [],
        ]);
    }
}
