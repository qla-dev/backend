<?php

namespace Tests\Unit;

use App\Services\AisVesselStreamClient;
use PHPUnit\Framework\TestCase;

class AisVesselStreamClientTest extends TestCase
{
    public function test_blank_metadata_uses_static_name_and_later_reports_preserve_it(): void
    {
        $client = new AisVesselStreamClient;
        $normalize = new \ReflectionMethod($client, 'normalize');
        $payload = [
            'MessageType' => 'ShipStaticData',
            'MetaData' => ['MMSI' => '538012044', 'ShipName' => '   '],
            'Message' => ['ShipStaticData' => ['Name' => 'TEST VESSEL@@']],
        ];
        $row = $normalize->invoke($client, $payload, []);
        $this->assertSame('TEST VESSEL', $row['name']);
        $payload['Message'] = [];
        $row = $normalize->invoke($client, $payload, ['538012044' => $row]);
        $this->assertSame('TEST VESSEL', $row['name']);
    }
}
