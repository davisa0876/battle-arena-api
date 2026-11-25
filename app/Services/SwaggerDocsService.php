<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Builds the OpenAPI document from Horus-style JSON fragments under resources/docs/swagger.
 *
 * Layout:
 *   resources/docs/swagger/paths/*.json
 *   resources/docs/swagger/components/schemas/*.json
 *   resources/docs/swagger/components/responses/*.json
 *   resources/docs/swagger/components/parameters/*.json
 *   resources/docs/swagger/components/securitySchemes/*.json
 */
final class SwaggerDocsService
{
    /**
     * @param  array<int, array{url: string, description?: string}>  $servers
     * @return array<string, mixed>
     */
    public function buildConfig(
        string $title = 'Battle Arena API',
        string $basePath = 'resources/docs/swagger',
        array $servers = [],
        ?string $infoDescription = null,
        string $version = '3.1.0',
    ): array {
        $config = $this->getBaseConfig($title, $servers, $infoDescription, $version);
        $this->processDocFiles($this->gatherDocsPaths($basePath), $config);
        $this->injectGlobalParameterRefs($config);

        return $config;
    }

    /**
     * @param  array<int, array{url: string, description?: string}>  $servers
     * @return array<string, mixed>
     */
    private function getBaseConfig(
        string $title,
        array $servers,
        ?string $infoDescription,
        string $version,
    ): array {
        if ($servers === []) {
            $servers = [['url' => url('/'), 'description' => 'Current host']];
        }

        $info = [
            'title' => $title,
            'version' => $version,
        ];
        if ($infoDescription !== null) {
            $info['description'] = $infoDescription;
        }

        return [
            'openapi' => '3.0.3',
            'info' => $info,
            'servers' => $servers,
            'tags' => [
                ['name' => 'Auth', 'description' => 'Registration, login and password recovery'],
                ['name' => 'Profile', 'description' => 'Trainer profile and avatar'],
                ['name' => 'Collection', 'description' => 'Owned creatures, starter and acquire'],
                ['name' => 'Game', 'description' => 'Published game rules and feature flags'],
                ['name' => 'Catalog', 'description' => 'Public creature catalog'],
                ['name' => 'PvE', 'description' => 'Solo arena battles'],
            ],
            'paths' => [],
            'components' => [
                'schemas' => [],
                'responses' => [],
                'parameters' => [],
                'securitySchemes' => [],
            ],
            'security' => [],
        ];
    }

    /**
     * @return Collection<int, SplFileInfo>
     */
    private function gatherDocsPaths(string $basePath): Collection
    {
        $fullBasePath = base_path($basePath);
        if (! File::exists($fullBasePath)) {
            return collect();
        }

        return collect(File::allFiles($fullBasePath))
            ->filter(static fn (SplFileInfo $file): bool => strtolower($file->getExtension()) === 'json')
            ->values();
    }

    /**
     * @param  Collection<int, SplFileInfo>  $paths
     * @param  array<string, mixed>  $config
     */
    private function processDocFiles(Collection $paths, array &$config): void
    {
        $paths->each(function (SplFileInfo $file) use (&$config): void {
            $normalized = str_replace('\\', '/', $file->getPath());
            $contents = json_decode($file->getContents(), true);
            if (! is_array($contents)) {
                return;
            }

            if (str_contains($normalized, '/paths')) {
                foreach ($contents as $key => $value) {
                    $config['paths'][$key] = $value;
                }

                return;
            }

            if (str_contains($normalized, '/components/schemas')) {
                foreach ($contents as $key => $value) {
                    $config['components']['schemas'][$key] = $value;
                }

                return;
            }

            if (str_contains($normalized, '/components/responses')) {
                foreach ($contents as $key => $value) {
                    $config['components']['responses'][$key] = $value;
                }

                return;
            }

            if (str_contains($normalized, '/components/parameters')) {
                foreach ($contents as $key => $value) {
                    $config['components']['parameters'][$key] = $value;
                }

                return;
            }

            if (str_contains($normalized, '/components/securitySchemes')) {
                foreach ($contents as $key => $value) {
                    $config['components']['securitySchemes'][$key] = $value;
                }
            }
        });
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function injectGlobalParameterRefs(array &$config): void
    {
        $refs = array_map(
            static fn (string $name): array => ['$ref' => "#/components/parameters/{$name}"],
            array_keys($config['components']['parameters'] ?? []),
        );

        if ($refs === []) {
            return;
        }

        $httpMethods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'];

        foreach ($config['paths'] as &$pathItem) {
            foreach ($httpMethods as $method) {
                if (! isset($pathItem[$method]) || ! is_array($pathItem[$method])) {
                    continue;
                }
                $existing = $pathItem[$method]['parameters'] ?? [];
                $pathItem[$method]['parameters'] = array_merge($refs, $existing);
            }
        }
        unset($pathItem);
    }
}
