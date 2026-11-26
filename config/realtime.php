<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public WebSocket URL returned to browsers / mobile clients
    |--------------------------------------------------------------------------
    |
    | Must be reachable from the client device. Never return the Compose
    | internal hostname (realtime:3001) to browsers or phones.
    |
    */

    'public_ws_url' => env('PUBLIC_WS_URL', 'ws://localhost:3001/ws'),

    /*
    |--------------------------------------------------------------------------
    | One-time ticket contract (shared with battle-arena-realtime)
    |--------------------------------------------------------------------------
    |
    | Keys are written with a raw Redis client (no Laravel REDIS_PREFIX) so
    | Node can redeem via GETDEL on: {prefix}{sha256(ticket)}.
    |
    */

    'ticket' => [
        'redis_connection' => env('WS_TICKET_REDIS_CONNECTION', 'ws_tickets'),
        'redis_prefix' => env('WS_TICKET_REDIS_PREFIX', 'battle-arena:ws-ticket:'),
        'ttl_seconds' => (int) env('WS_TICKET_TTL_SECONDS', 30),
        'bytes' => 32,
    ],

    'rate_limit_per_minute' => (int) env('WS_TICKET_RATE_LIMIT_PER_MINUTE', 30),

];
