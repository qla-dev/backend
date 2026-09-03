<?php

namespace Tests\Unit;

use App\Services\OpenWatersVesselClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenWatersVesselClientTest extends TestCase
{
    public function test_it_normalizes_a_live_mmsi_snapshot(): void
    {
        Http::fake([
            'ais.openwaters.io/v1/vessels*' => Http::response([
                'type' => 'FeatureCollection',
                'features' => [[
                    'type' => 'Feature',
                    'id' => 249533000,
                    'geometry' => [
                        'type' => 'Point',
                        'coordinates' => [31.258188333333333, 31.759386666666668],
                    ],
                    'properties' => [
                        'mmsi' => 249533000,
                        'sog' => 10.1,
                        'cog' => 265.2,
                        'heading' => 266,
                        'nav_status' => 0,
                        'seen' => '2026-09-03T13:22:21Z',
                        'source' => 'aishub',
                    ],
                ]],
            ]),
        ]);

        $rows = app(OpenWatersVesselClient::class)->capture(-90, -180, 90, 180, ['249533000']);

        $this->assertSame('249533000', $rows[0]['mmsi']);
        $this->assertSame(31.759386666666668, $rows[0]['lat']);
        $this->assertSame(31.258188333333333, $rows[0]['lon']);
        $this->assertSame(10.1, $rows[0]['speed']);
        $this->assertSame('aishub', $rows[0]['source']);
        Http::assertSent(fn ($request): bool => $request['mmsi'] === '249533000');
    }
}
