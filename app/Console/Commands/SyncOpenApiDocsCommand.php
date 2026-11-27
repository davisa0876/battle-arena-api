<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\SwaggerDocsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Builds OpenAPI from Horus-style JSON fragments and publishes for L5-Swagger UI.
 * Source of truth: resources/docs/swagger/{paths,components}/*.json
 */
final class SyncOpenApiDocsCommand extends Command
{
    protected $signature = 'openapi:sync';

    protected $description = 'Build OpenAPI from resources/docs/swagger fragments into docs/ + storage/api-docs';

    public function handle(SwaggerDocsService $swagger): int
    {
        $config = $swagger->buildConfig(
            title: 'Battle Arena API',
            basePath: 'resources/docs/swagger',
            servers: [
                ['url' => 'http://localhost:8000', 'description' => 'Local Compose API'],
            ],
            infoDescription: <<<'MD'
Phase 3+ contract: accounts, profiles, owned creatures, game rules, PvE arena battles,
and Phase 5B.1 private PvP lobbies/invitations (no combat/XP yet).

Authentication: web uses Sanctum cookie sessions (call `GET /sanctum/csrf-cookie` first, send
`X-XSRF-TOKEN`, omit `device_name`). Mobile sends `device_name` and receives a bearer token.
Localized messages follow `Accept-Language` (en, fr, pt-BR, it, es).

Docs are maintained as Horus-style JSON fragments under `resources/docs/swagger/`.
MD
        );

        $docsPath = (string) config('l5-swagger.defaults.paths.docs', base_path('storage/api-docs'));
        File::ensureDirectoryExists($docsPath);

        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        File::put($docsPath.'/api-docs.json', $json."\n");
        File::put(base_path('docs/openapi.json'), $json."\n");

        try {
            $yaml = Yaml::dump($config, 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);
            File::put($docsPath.'/api-docs.yaml', $yaml);
            File::put(base_path('docs/openapi.yaml'), $yaml);
        } catch (Throwable $e) {
            $this->error('Failed to write OpenAPI YAML: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Synced OpenAPI from resources/docs/swagger → docs/ + storage/api-docs');

        return self::SUCCESS;
    }
}
