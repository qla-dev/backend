<?php

namespace Tests\Feature\Api;

use App\Http\Controllers\Api\AircraftController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AircraftSearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /** @param  array<int, array<string, mixed>>  $aircraft */
    private function payload(array $aircraft, string $key): array
    {
        return [$key => $aircraft, 'resultCount' => count($aircraft), 'total' => count($aircraft)];
    }

    private function search(string $term): array
    {
        $response = (new AircraftController())->index(
            Request::create('/api/aircraft', 'GET', ['search' => $term]),
        );

        return json_decode($response->getContent(), true);
    }

    public function test_it_resolves_a_hyphenated_registration_through_the_documented_api(): void
    {
        $aircraft = ['hex' => '782398', 'flight' => 'JDL2646 ', 'r' => 'B-227Y', 'lat' => 31.2, 'lon' => 121.3];
        Http::fake([
            'api.adsb.lol/v2/reg/B-227Y' => Http::response($this->payload([$aircraft], 'ac')),
            '*' => Http::response($this->payload([], 'ac')),
        ]);

        $body = $this->search('B-227Y');

        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame('B-227Y', $body['data'][0]['r']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.adsb.lol/v2/reg/B-227Y'));
    }

    public function test_it_rebuilds_the_hyphen_the_registration_lookup_requires(): void
    {
        // "B226S" is never matched by the exact lookups; only "B-226S" resolves.
        $aircraft = ['hex' => '782397', 'flight' => 'JDL2672 ', 'r' => 'B-226S', 'lat' => 31.2, 'lon' => 121.3];
        Http::fake([
            'adsb.lol/re-api/*find_reg=B-226S*' => Http::response($this->payload([$aircraft], 'aircraft')),
            'adsb.lol/re-api/*' => Http::response($this->payload([], 'aircraft')),
            '*' => Http::response($this->payload([], 'ac')),
        ]);

        $body = $this->search('B226S');

        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame('B-226S', $body['data'][0]['r']);
    }

    public function test_it_searches_the_whole_network_rather_than_the_map_viewport(): void
    {
        $aircraft = ['hex' => '782398', 'flight' => 'JDL2646 ', 'r' => 'B-227Y', 'lat' => 31.2, 'lon' => 121.3];
        Http::fake([
            'api.adsb.lol/v2/callsign/JDL2646' => Http::response($this->payload([$aircraft], 'ac')),
            '*' => Http::response($this->payload([], 'ac')),
        ]);

        // Bounds over Germany must not hide an aircraft over China.
        $response = (new AircraftController())->index(Request::create('/api/aircraft', 'GET', [
            'south' => 49.0, 'west' => 7.0, 'north' => 51.0, 'east' => 10.0, 'search' => 'JDL2646',
        ]));
        $body = json_decode($response->getContent(), true);

        $this->assertSame(1, $body['meta']['count']);
        $this->assertSame('B-227Y', $body['data'][0]['r']);
    }

    public function test_it_reports_an_aircraft_that_is_not_transmitting(): void
    {
        Http::fake([
            'adsb.lol/re-api/*' => Http::response($this->payload([], 'aircraft')),
            '*' => Http::response($this->payload([], 'ac')),
        ]);

        $body = $this->search('JDL2672');

        $this->assertSame(0, $body['meta']['count']);
        $this->assertSame('No aircraft matching this search is transmitting right now.', $body['message']);
    }

    public function test_it_stops_at_the_first_identifier_that_resolves(): void
    {
        $aircraft = ['hex' => '4ca7b7', 'flight' => 'RYR7WA  ', 'r' => 'EI-EGB', 'lat' => 50.1, 'lon' => 8.5];
        Http::fake([
            'api.adsb.lol/v2/callsign/RYR7WA' => Http::response($this->payload([$aircraft], 'ac')),
            '*' => Http::response($this->payload([], 'ac')),
        ]);

        $this->search('RYR7WA');

        // The documented API is rate limited, so a hit must not trigger the fallbacks.
        Http::assertSentCount(1);
    }

    public function test_it_finds_a_grounded_aircraft_through_the_registry(): void
    {
        Http::fake([
            'adsb.lol/db2/7.js' => Http::response(['82177' => ['B-226S', 'B738', '00', 'BOEING 737-800']]),
            'adsb.lol/data/traces/77/trace_recent_782177.json' => Http::response([
                'r' => 'B-226S', 't' => 'B738',
                'timestamp' => 1788631271,
                'trace' => [[0, 30.1, 114.1, 32000], [600, 30.501434, 114.617292, 30100]],
            ]),
            '*' => Http::response($this->payload([], 'ac')),
        ]);

        $body = $this->search('B-226S');

        $this->assertSame(1, $body['meta']['count']);
        $aircraft = $body['data'][0];
        $this->assertSame('B-226S', $aircraft['r']);
        $this->assertSame('BOEING 737-800', $aircraft['desc']);
        $this->assertSame('782177', $aircraft['hex']);
        // The last trace fix stands in for a live position.
        $this->assertSame('last_seen', $aircraft['position_source']);
        $this->assertSame(30.501434, $aircraft['lat']);
        $this->assertSame(1788631871, $aircraft['seen_at']);
    }

    public function test_it_does_not_sweep_the_registry_for_a_callsign(): void
    {
        Http::fake(['*' => Http::response($this->payload([], 'ac'))]);

        $this->search('JDL2672');

        // "JDL" is not a registration prefix, so no registry shard should be read.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/db2/'));
    }

    public function test_it_reports_an_outage_when_no_lookup_answers(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $response = (new AircraftController())->index(
            Request::create('/api/aircraft', 'GET', ['search' => 'RYR7WA']),
        );

        $this->assertSame(502, $response->getStatusCode());
    }
}
