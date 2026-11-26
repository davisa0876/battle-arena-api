<?php

namespace App\OpenApi;

use OpenApi\Attributes as OA;

/**
 * Base OpenAPI metadata for L5-Swagger discovery.
 *
 * Full path/schema definitions live as Horus-style JSON fragments under
 * resources/docs/swagger/. Run `php artisan openapi:sync` after editing them.
 */
#[OA\Info(
    version: '3.1.0',
    title: 'Battle Arena API',
    description: 'Accounts, profiles, collection, game rules, PvE, private PvP lobbies and authoritative two-human PvP combat. Prefer resources/docs/swagger fragments (synced via openapi:sync).'
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
#[OA\Tag(name: 'PvE', description: 'Solo arena battles')]
#[OA\Tag(name: 'PvP', description: 'Private lobbies, invitations, creature selection and two-human combat (features.pvp remains false)')]
#[OA\Tag(name: 'Realtime', description: 'WebSocket ticket bootstrap for the Node gateway')]
final class OpenApiSpec
{
}
