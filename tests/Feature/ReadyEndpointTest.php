<?php

namespace Tests\Feature;

use Tests\TestCase;

class ReadyEndpointTest extends TestCase
{
    public function test_ready_returns_json_structure_and_valid_status_code(): void
    {
        $response = $this->getJson('/api/ready');

        $this->assertContains($response->status(), [200, 503]);

        $response->assertJsonStructure([
            'status',
            'service',
            'checks' => [
                'postgres',
                'redis',
            ],
        ])->assertJson([
            'service' => 'battle-arena-api',
        ]);

        $this->assertContains($response->json('checks.postgres'), ['ok', 'fail']);
        $this->assertContains($response->json('checks.redis'), ['ok', 'fail']);
    }
}
