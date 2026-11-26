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
    ],

    'rate_limits' => [
        'mutations_per_minute' => (int) env('PVP_MUTATIONS_PER_MINUTE', 30),
        'grants_per_minute' => (int) env('PVP_GRANTS_PER_MINUTE', 30),
    ],

];
