<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\VesselController;
use App\Services\Contracts\VesselStreamClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VesselApiTest extends TestCase
{
    public function test_exact_mmsi_search_uses_a_global_filtered_subscription(): void
    {
        $stream = new RecordingVesselStreamClient([[
            'mmsi' => '249533000',
            'name' => 'CMA CGM ARGON',
            'lat' => 31.2,
            'lon' => 29.8,
            'updated_at' => now()->toIso8601String(),
        ]]);
        $request = Request::create('/api/vessels', 'GET', [
            'south' => 40,
            'west' => 10,
            'north' => 46,
            'east' => 20,
            'search' => '249533000',
        ]);

        $response = app(VesselController::class)->index($request, $stream);

        $this->assertSame([-90.0, -180.0, 90.0, 180.0, 8.0, ['249533000']], $stream->lastCapture);
        $this->assertSame('249533000', $response->getData(true)['data'][0]['mmsi']);
        $this->assertTrue($response->getData(true)['meta']['global_search']);
    }

    public function test_text_search_checks_cached_vessels_outside_the_viewport(): void
    {
        Cache::put('live-vessels', [
            '249533000' => [
                'mmsi' => '249533000',
                'name' => 'CMA CGM ARGON',
                'lat' => 31.2,
                'lon' => 29.8,
                'updated_at' => now()->toIso8601String(),
            ],
        ]);
        $stream = new RecordingVesselStreamClient([]);
        $request = Request::create('/api/vessels', 'GET', [
            'south' => 40,
            'west' => 10,
            'north' => 46,
            'east' => 20,
            'search' => 'argon',
        ]);

        $response = app(VesselController::class)->index($request, $stream);

        $this->assertSame('249533000', $response->getData(true)['data'][0]['mmsi']);
    }
}

class RecordingVesselStreamClient implements VesselStreamClient
{
    public ?array $lastCapture = null;

    public function __construct(private readonly array $updates) {}

    public function capture(
        float $south,
        float $west,
        float $north,
        float $east,
        float $seconds = 2.5,
        array $mmsis = [],
    ): array {
        $this->lastCapture = [$south, $west, $north, $east, $seconds, $mmsis];

        return $this->updates;
    }
}
