<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FuelStation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FuelStationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'south' => ['required', 'numeric', 'between:-90,90'],
            'west' => ['required', 'numeric', 'between:-180,180'],
            'north' => ['required', 'numeric', 'between:-90,90', 'gt:south'],
            'east' => ['required', 'numeric', 'between:-180,180', 'gt:west'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ]);

        $stations = FuelStation::query()
            ->whereBetween('latitude', [(float) $data['south'], (float) $data['north']])
            ->whereBetween('longitude', [(float) $data['west'], (float) $data['east']])
            ->limit((int) ($data['limit'] ?? 1000))
            ->get([
                'id', 'source', 'source_type', 'source_id', 'name', 'brand', 'operator', 'address',
                'latitude', 'longitude', 'opening_hours', 'hgv', 'fuel_types', 'last_synced_at',
            ]);

        return response()->json([
            'message' => 'Fuel stations retrieved.',
            'data' => $stations,
            'meta' => [
                'count' => $stations->count(),
                'attribution' => 'Fuelo.net',
                'source_url' => 'https://de.fuelo.net/',
            ],
            'errors' => [],
        ]);
    }
}
