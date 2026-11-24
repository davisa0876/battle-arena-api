<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default catalog provider
    |--------------------------------------------------------------------------
    |
    | Provider implementations must map into ExternalCreatureData. The HTTP API
    | never returns raw upstream payloads.
    |
    */

    'default_provider' => env('CREATURE_CATALOG_PROVIDER', 'pokeapi'),

    'providers' => [
        'pokeapi' => [
            'base_url' => env('POKEAPI_BASE_URL', 'https://pokeapi.co/api/v2'),
            'timeout_seconds' => (float) env('POKEAPI_TIMEOUT_SECONDS', 8),
            'retry_times' => (int) env('POKEAPI_RETRY_TIMES', 2),
            'retry_sleep_ms' => (int) env('POKEAPI_RETRY_SLEEP_MS', 250),
            'request_delay_ms' => (int) env('POKEAPI_REQUEST_DELAY_MS', 100),
            'cache_ttl_seconds' => (int) env('POKEAPI_CACHE_TTL_SECONDS', 3600),
        ],
    ],

];
