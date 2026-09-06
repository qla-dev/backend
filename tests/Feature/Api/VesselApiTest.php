<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\VesselController;
use App\Services\Contracts\VesselSnapshotClient;
use App\Services\Contracts\VesselStreamClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VesselApiTest extends TestCase
{
    public function test_exact_mmsi_search_uses_open_waters_first(): void
    {
        $primary = new RecordingVesselSnapshotClient([[
            'mmsi' => '249533000',
            'name' => 'CMA CGM ARGON',
            'lat' => 31.2,
            'lon' => 29.8,
            'updated_at' => now()->toIso8601String(),
        ]]);
        $fallback = new RecordingVesselStreamClient([]);
        $request = Request::create('/api/vessels', 'GET', [
            'south' => 40,
            'west' => 10,
            'north' => 46,
            'east' => 20,
            'search' => '249533000',
        ]);

        $response = app(VesselController::class)->index($request, $primary, $fallback);

        $this->assertSame([-90.0, -180.0, 90.0, 180.0, ['249533000']], $primary->lastCapture);
        $this->assertNull($fallback->lastCapture);
        $this->assertSame('249533000', $response->getData(true)['data'][0]['mmsi']);
        $this->assertTrue($response->getData(true)['meta']['global_search']);
    }

    public function test_aisstream_is_used_when_open_waters_has_no_match(): void
    {
        $primary = new RecordingVesselSnapshotClient([]);
        $fallback = new RecordingVesselStreamClient([[
            'mmsi' => '249533000',
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

        $response = app(VesselController::class)->index($request, $primary, $fallback);

        $this->assertSame([-90.0, -180.0, 90.0, 180.0, 8.0, ['249533000']], $fallback->lastCapture);
        $this->assertSame('249533000', $response->getData(true)['data'][0]['mmsi']);
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
        $primary = new RecordingVesselSnapshotClient([]);
        $fallback = new RecordingVesselStreamClient([]);
        $request = Request::create('/api/vessels', 'GET', [
            'south' => 40,
            'west' => 10,
            'north' => 46,
            'east' => 20,
            'search' => 'argon',
        ]);

        $response = app(VesselController::class)->index($request, $primary, $fallback);

        $this->assertSame('249533000', $response->getData(true)['data'][0]['mmsi']);
    }

    public function test_position_only_snapshot_is_enriched_and_search_finds_names_globally(): void
    {
        Cache::flush();
        $primary = new RecordingVesselSnapshotClient([[
            'mmsi' => '538012044', 'lat' => 31.2, 'lon' => 29.8,
            'updated_at' => now()->toIso8601String(), 'speed' => 3.5,
        ]]);
        $fallback = new RecordingVesselStreamClient([[
            'mmsi' => '538012044', 'name' => 'TEST VESSEL',
            'updated_at' => now()->toIso8601String(),
        ]]);
        foreach (['538012044', 'test vessel'] as $search) {
            Cache::flush();
            $request = Request::create('/api/vessels', 'GET', [
                'search' => $search,
            ]);
            $data = app(VesselController::class)->index($request, $primary, $fallback)->getData(true)['data'];
            $this->assertSame('TEST VESSEL', $data[0]['name']);
            $this->assertSame(3.5, $data[0]['speed']);
            $this->assertSame([-90.0, -180.0, 90.0, 180.0, 8.0, $search === '538012044' ? [$search] : []], $fallback->lastCapture);
        }
    }
}

class RecordingVesselSnapshotClient implements VesselSnapshotClient
{
    public ?array $lastCapture = null;

    public function __construct(private readonly array $updates) {}

    public function capture(
        float $south,
        float $west,
        float $north,
        float $east,
        array $mmsis = [],
        string $search = '',
    ): array {
        $this->lastCapture = [$south, $west, $north, $east, $mmsis];

        return $this->updates;
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
