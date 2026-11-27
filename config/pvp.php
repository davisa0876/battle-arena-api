<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Private lobby invitations (Phase 5B.1)
    |--------------------------------------------------------------------------
    |
    | Combat/XP remain disabled. features.pvp in config/game.php stays false.
    |
    */

    'invitation_ttl_seconds' => (int) env('PVP_INVITATION_TTL_SECONDS', 600),

    'room_grant' => [
        'redis_connection' => env('WS_ROOM_GRANT_REDIS_CONNECTION', 'ws_tickets'),
        'redis_prefix' => env('WS_ROOM_GRANT_REDIS_PREFIX', 'battle-arena:ws-room-grant:'),
        'ttl_seconds' => (int) env('WS_ROOM_GRANT_TTL_SECONDS', 30),
        'bytes' => 32,
    ],

    'realtime' => [
        'channel' => env('PVP_REALTIME_CHANNEL', 'battle-arena:realtime:notifications:v1'),

        /*
        | Notification transport:
        | - redis  (default): PUBLISH to `channel`; the persistent Node gateway subscribes.
        | - lambda: async-invoke (InvocationType=Event) the realtime Lambda behind
        |           API Gateway WebSocket. No long-lived subscriber is required.
        | Both transports carry the identical hint-only envelope.
        */
        'driver' => env('PVP_REALTIME_DRIVER', 'redis'),

        'lambda' => [
            'function' => env('PVP_REALTIME_LAMBDA_FUNCTION', 'battle-arena-realtime'),
            'region' => env('PVP_REALTIME_LAMBDA_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
            // Local emulator override only; leave empty in AWS to use the regional endpoint.
            'endpoint' => env('PVP_REALTIME_LAMBDA_ENDPOINT') ?: null,
            'timeout_seconds' => (float) env('PVP_REALTIME_LAMBDA_TIMEOUT_SECONDS', 2),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Online opponents (WS presence SET written by battle-arena-realtime)
    |--------------------------------------------------------------------------
    */

    'presence' => [
        'redis_connection' => env('PVP_PRESENCE_REDIS_CONNECTION', env('WS_ROOM_GRANT_REDIS_CONNECTION', 'ws_tickets')),
        'online_set_key' => env('PVP_PRESENCE_ONLINE_SET_KEY', 'battle-arena:presence:online'),
        // Must match battle-arena-realtime PRESENCE_CONN_HASH_KEY.
        'conn_hash_key' => env('PVP_PRESENCE_CONN_HASH_KEY', 'battle-arena:presence:conns'),
        'max_results' => (int) env('PVP_PRESENCE_MAX_RESULTS', 40),
    ],


    'rate_limits' => [
        'mutations_per_minute' => (int) env('PVP_MUTATIONS_PER_MINUTE', 30),
        'grants_per_minute' => (int) env('PVP_GRANTS_PER_MINUTE', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Phase 5B.4 — forfeit / action deadlines (proposed MVP policy)
    |--------------------------------------------------------------------------
    |
    | Noncombat XP 0/0 and 300s deadlines are PROPOSED defaults pending owner
    | approval. features.pvp / public_enabled stay false until release sign-off.
    | Turn timeout adjudication stays OFF until scheduler + smoke are proven.
    |
    */

    'forfeit_enabled' => (bool) env('PVP_FORFEIT_ENABLED', true),

    'turn_timeout' => [
        'enabled' => (bool) env('PVP_TURN_TIMEOUT_ENABLED', false),
        'seconds' => (int) env('PVP_TURN_TIMEOUT_SECONDS', 300),
        'batch_size' => (int) env('PVP_TURN_TIMEOUT_BATCH_SIZE', 50),
    ],

    'noncombat' => [
        'win_xp' => (int) env('PVP_NONCOMBAT_WIN_XP', 0),
        'loss_xp' => (int) env('PVP_NONCOMBAT_LOSS_XP', 0),
    ],

    'public_enabled' => (bool) env('PVP_PUBLIC_ENABLED', false),

    'preview_enabled' => (bool) env('PVP_PREVIEW_ENABLED', false),

];
