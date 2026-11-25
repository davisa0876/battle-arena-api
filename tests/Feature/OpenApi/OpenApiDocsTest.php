<?php

namespace Tests\Feature\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    public function test_openapi_sync_publishes_yaml_and_json_for_swagger_ui(): void
    {
        $exit = Artisan::call('openapi:sync');

        $this->assertSame(0, $exit);
        $this->assertFileExists(storage_path('api-docs/api-docs.yaml'));
        $this->assertFileExists(storage_path('api-docs/api-docs.json'));

        $json = json_decode((string) file_get_contents(storage_path('api-docs/api-docs.json')), true);
        $this->assertIsArray($json);
        $this->assertSame('3.0.3', $json['openapi'] ?? null);
        $this->assertSame('Battle Arena API', $json['info']['title'] ?? null);
        $this->assertArrayHasKey('/api/login', $json['paths'] ?? []);
    }

    public function test_swagger_ui_is_reachable_after_sync(): void
    {
        Artisan::call('openapi:sync');

        $this->get('/api/documentation')
            ->assertOk()
            ->assertSee('swagger', false);
    }
}
