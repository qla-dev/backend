<?php

namespace Tests\Feature;

use Tests\TestCase;

final class OpsGuestResponseTest extends TestCase
{
    public function test_protected_ops_routes_return_json_to_a_browser_guest(): void
    {
        foreach (['/api/accounting/1/ops/operational-overview', '/api/accounting/1/ops/today', '/api/transport-orders'] as $path) {
            $this->get($path, ['Accept' => 'text/html'])
                ->assertUnauthorized()
                ->assertJsonStructure(['message']);
        }
    }
}
