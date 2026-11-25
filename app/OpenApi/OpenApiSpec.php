<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Base OpenAPI document metadata for L5-Swagger.
 *
 * Full path/schema definitions live in docs/openapi.yaml (source of truth).
 * Run `php artisan openapi:sync` after editing that file so Swagger UI picks them up.
 */
#[OA\Info(
    version: '3.1.0',
    title: 'Battle Arena API',
    description: 'Phase 3 contract: accounts, profiles, owned creatures, catalog selection flags and game rules. Prefer docs/openapi.yaml for the full HTTP contract.'
)]
#[OA\Server(url: 'http://localhost:8000', description: 'Local Compose API')]
#[OA\SecurityScheme(
    securityScheme: 'sanctum',
    type: 'http',
    description: 'Sanctum bearer token (mobile). Web SPA uses cookie session + CSRF instead.',
    scheme: 'bearer',
    bearerFormat: 'Sanctum'
)]
#[OA\Tag(name: 'Auth', description: 'Registration, login and password recovery')]
#[OA\Tag(name: 'Profile', description: 'Trainer profile and avatar')]
#[OA\Tag(name: 'Collection', description: 'Owned creatures, starter and acquire')]
#[OA\Tag(name: 'Game', description: 'Published game rules and feature flags')]
#[OA\Tag(name: 'Catalog', description: 'Public creature catalog')]
final class OpenApiSpec
{
}
