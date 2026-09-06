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
            'Message' => ['ShipStaticData' => ['Name' => 'TEST VESSEL@@', 'ImoNumber' => 9210218,
                'MaximumStaticDraught' => 5.9, 'Dimension' => ['A' => 150, 'B' => 31, 'C' => 12, 'D' => 13]]],
        ];
        $row = $normalize->invoke($client, $payload, []);
        $this->assertSame('TEST VESSEL', $row['name']);
        $this->assertSame(9210218.0, $row['imo']);
        $this->assertSame(5.9, $row['draught']);
        $this->assertSame(181.0, $row['length']);
        $this->assertSame(25.0, $row['beam']);
        $payload['Message'] = [];
        $row = $normalize->invoke($client, $payload, ['538012044' => $row]);
        $this->assertSame('TEST VESSEL', $row['name']);
    }
}
