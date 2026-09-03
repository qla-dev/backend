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
        $validated = $request->validate([
            'south' => ['required', 'numeric', 'between:-90,90'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'north' => ['required', 'numeric', 'between:-90,90', 'gt:south'],
            'east' => ['required', 'numeric', 'between:-180,180'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        [$south, $west, $north, $east] = array_map('floatval', [$validated['south'], $validated['west'], $validated['north'], $validated['east']]);
        $search = trim((string) ($validated['search'] ?? ''));
        $searchedMmsi = preg_match('/^\d{9}$/', $search) === 1 ? $search : null;

        try {
            // AISStream can search a vessel globally by MMSI without subscribing to the
            // extremely high-volume world feed. A slightly longer capture gives a live
            // position report time to arrive for the requested vessel.
            $updates = $searchedMmsi !== null
                ? $stream->capture(-90, -180, 90, 180, 8.0, [$searchedMmsi])
                : $stream->capture($south, $west, $north, $east);
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
            if ($key !== '') {
                $stored[$key] = array_merge($stored[$key] ?? [], $update);
            }
        }
        $cutoff = now()->subMinutes(15);
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
            'meta' => ['count' => $vessels->count(), 'configured' => true, 'global_search' => $search !== ''], 'errors' => [],
        ]);
    }
}
