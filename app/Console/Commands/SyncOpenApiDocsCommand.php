<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Publishes docs/openapi.yaml into storage/api-docs for L5-Swagger UI.
 * Keeps the hand-written YAML as the contract source of truth.
 */
class SyncOpenApiDocsCommand extends Command
{
    protected $signature = 'openapi:sync';

    protected $description = 'Copy docs/openapi.yaml into storage/api-docs for Swagger UI';

    public function handle(): int
    {
        $source = base_path('docs/openapi.yaml');
        if (! File::isFile($source)) {
            $this->error('Missing source file: docs/openapi.yaml');

            return self::FAILURE;
        }

        $docsPath = storage_path('api-docs');
        File::ensureDirectoryExists($docsPath);

        $yamlTarget = $docsPath.'/api-docs.yaml';
        $jsonTarget = $docsPath.'/api-docs.json';

        File::copy($source, $yamlTarget);

        try {
            $parsed = Yaml::parseFile($source);
            File::put($jsonTarget, json_encode($parsed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->error('Failed to convert OpenAPI YAML to JSON: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Synced OpenAPI docs to storage/api-docs (yaml + json).');

        return self::SUCCESS;
    }
}
