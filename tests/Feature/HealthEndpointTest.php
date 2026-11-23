<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_returns_ok_payload(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'service' => 'battle-arena-api',
                'version' => '0.1.0',
            ]);
    }
}
