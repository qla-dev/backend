<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\VesselController;
use App\Services\Contracts\VesselStreamClient;
use App\Support\VesselReference;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class VesselDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function stream(array $updates = []): VesselStreamClient
    {
        return new class($updates) implements VesselStreamClient
        {
            public function __construct(private array $updates) {}

            public function capture(float $south, float $west, float $north, float $east, float $seconds = 2.5, array $mmsis = []): array
            {
                return $this->updates;
            }
        };
    }

    private function details(string $mmsi, array $updates = []): array
    {
        $response = (new VesselController())->details($mmsi, $this->stream($updates));

        return [$response->getStatusCode(), json_decode($response->getContent(), true)['data']];
    }

    public function test_it_decodes_the_coded_fields_an_ais_report_carries(): void
    {
        Cache::put('live-vessels', ['636019825' => [
            'mmsi' => '636019825', 'name' => 'EVER GIVEN', 'callsign' => '5LDN2',
            'lat' => 30.02, 'lon' => 32.58, 'speed' => 12.4, 'course' => 137.0, 'heading' => 140.0,
            'navigation_status' => 0, 'ship_type' => 71, 'destination' => 'ROTTERDAM',
            'updated_at' => now()->toIso8601String(), 'provider' => 'open_waters',
        ]], now()->addMinutes(20));

        [$status, $data] = $this->details('636019825');

        $this->assertSame(200, $status);
        $this->assertSame('EVER GIVEN', $data['name']);
        $this->assertSame(['code' => 'LR', 'name' => 'Liberia'], $data['country']);
        $this->assertSame('Cargo', $data['ship_type']);
        $this->assertSame('Under way using engine', $data['navigation_status']);
        $this->assertSame('ROTTERDAM', $data['destination']);
        $this->assertSame(12.4, $data['speed']);
    }

    public function test_it_falls_back_to_the_provider_when_the_cache_has_aged_out(): void
    {
        [$status, $data] = $this->details('244019825', [
            ['mmsi' => '244019825', 'name' => 'NEDLLOYD', 'lat' => 51.9, 'lon' => 4.1, 'ship_type' => 80],
        ]);

        $this->assertSame(200, $status);
        $this->assertSame('NEDLLOYD', $data['name']);
        $this->assertSame(['code' => 'NL', 'name' => 'Netherlands'], $data['country']);
        $this->assertSame('Tanker', $data['ship_type']);
    }

    public function test_it_rejects_an_identifier_that_is_not_an_mmsi(): void
    {
        $this->assertSame(422, (new VesselController())->details('12345', $this->stream())->getStatusCode());
    }

    public function test_it_reports_a_vessel_that_is_not_broadcasting(): void
    {
        $this->assertSame(404, (new VesselController())->details('999999999', $this->stream())->getStatusCode());
    }

    /** An unknown registry must show no flag rather than the wrong one. */
    public function test_it_leaves_an_unassigned_flag_state_blank(): void
    {
        $this->assertNull(VesselReference::flagState('999999999'));
        $this->assertSame(['code' => 'PA', 'name' => 'Panama'], VesselReference::flagState('351234567'));
    }

    public function test_it_groups_ship_types_by_their_ais_decade(): void
    {
        $this->assertSame('Cargo', VesselReference::shipType(79));
        $this->assertSame('Tanker', VesselReference::shipType(89));
        $this->assertSame('Passenger', VesselReference::shipType(60));
        $this->assertSame('Tug', VesselReference::shipType(52));
        $this->assertNull(VesselReference::shipType(null));
    }
}
