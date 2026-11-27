<?php

namespace Tests\Feature\OpenApi;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class OpenApiDocsTest extends TestCase
{
    public function test_openapi_sync_publishes_yaml_and_json_for_swagger_ui(): void
    {
        $exit = Artisan::call('openapi:sync');
        // Swagger UI reads the spec from the configured docs path (inside the app package).
        $docs = (string) config('l5-swagger.defaults.paths.docs');

        $this->assertSame(0, $exit);
        $this->assertFileExists($docs.'/api-docs.yaml');
        $this->assertFileExists($docs.'/api-docs.json');
        $this->assertFileExists(base_path('docs/openapi.yaml'));
        $this->assertFileExists(base_path('docs/openapi.json'));

        $json = json_decode((string) file_get_contents($docs.'/api-docs.json'), true);
        $this->assertIsArray($json);
        $this->assertSame('3.0.3', $json['openapi'] ?? null);
        $this->assertSame('Battle Arena API', $json['info']['title'] ?? null);
        $this->assertArrayHasKey('/api/login', $json['paths'] ?? []);
        $this->assertArrayHasKey('/api/pve/battles', $json['paths'] ?? []);
        $this->assertArrayHasKey('/api/pve/battles/{battle}/attack', $json['paths'] ?? []);
        $this->assertArrayHasKey('PveBattle', $json['components']['schemas'] ?? []);
        $this->assertArrayHasKey('PveAttackRequest', $json['components']['schemas'] ?? []);
        $this->assertArrayHasKey('recover', $json['components']['schemas']['PveAttackRequest']['properties'] ?? []);
    }

    public function test_swagger_json_route_returns_merged_horus_style_docs(): void
    {
        $response = $this->getJson('/api/swagger.json');

        $response->assertOk()
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('info.title', config('app.name', 'Battle Arena API'))
            ->assertJsonStructure([
                'paths' => [
                    '/api/login',
                    '/api/pve/battles',
                ],
                'components' => [
                    'schemas' => [
                        'PveBattle',
                        'Error',
                    ],
                ],
            ]);
    }

    public function test_swagger_ui_is_reachable_after_sync(): void
    {
        Artisan::call('openapi:sync');

        $this->get('/api/documentation')
            ->assertOk()
            ->assertSee('swagger', false);
    }
}
