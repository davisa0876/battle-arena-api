<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsAndSanctumConfigTest extends TestCase
{
    public function test_preflight_allows_credentials_from_the_frontend_origin(): void
    {
        $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'content-type,x-xsrf-token',
        ])
            ->assertSuccessful()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:3000')
            ->assertHeader('Access-Control-Allow-Credentials', 'true');
    }

    public function test_unknown_origins_do_not_receive_cors_headers(): void
    {
        $response = $this->call('OPTIONS', '/api/login', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $this->assertNotSame('https://evil.example', $response->headers->get('Access-Control-Allow-Origin'));
    }

    public function test_stateful_domains_come_from_the_environment_list(): void
    {
        $this->assertContains('localhost:3000', config('sanctum.stateful'));
        $this->assertContains('127.0.0.1:3000', config('sanctum.stateful'));
    }

    public function test_avatar_disks_are_private_and_local_is_always_available(): void
    {
        $local = config('filesystems.disks.profiles_local');

        $this->assertSame('local', $local['driver']);
        $this->assertSame(storage_path('app/profiles'), $local['root']);
        $this->assertSame('private', $local['visibility']);
        $this->assertTrue($local['throw']);
        $this->assertSame('profiles_local', config('game.avatar.disk'));
        $this->assertSame('s3', config('filesystems.disks.profiles.driver'));
    }
}
