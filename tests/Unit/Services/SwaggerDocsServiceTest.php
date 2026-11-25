<?php

namespace Tests\Unit\Services;

use App\Services\SwaggerDocsService;
use Tests\TestCase;

class SwaggerDocsServiceTest extends TestCase
{
    public function test_builds_config_from_fragment_files(): void
    {
        $service = new SwaggerDocsService;
        $config = $service->buildConfig(
            title: 'Battle Arena API',
            basePath: 'resources/docs/swagger',
            servers: [['url' => 'http://localhost:8000']],
        );

        $this->assertSame('3.0.3', $config['openapi']);
        $this->assertSame('Battle Arena API', $config['info']['title']);
        $this->assertArrayHasKey('/api/login', $config['paths']);
        $this->assertArrayHasKey('/api/pve/battles/{battle}/attack', $config['paths']);
        $this->assertArrayHasKey('PveBattle', $config['components']['schemas']);
        $this->assertArrayHasKey('sanctum', $config['components']['securitySchemes']);
        $this->assertArrayHasKey('ValidationError', $config['components']['responses']);
    }
}
