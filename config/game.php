<?php

$frontendUrl = rtrim(trim(explode(',', (string) env('FRONTEND_URL', 'http://localhost:3000'))[0]), '/');

return [

    /*
    |--------------------------------------------------------------------------
    | Game Rules Version
    |--------------------------------------------------------------------------
    |
    | Bumped whenever the machine-readable rules exposed at GET /api/game/rules
    | change in a way clients should notice.
    |
    */

    'rules_version' => '3.1.0',

    /*
    |--------------------------------------------------------------------------
    | Trainer Progression (authoritative)
    |--------------------------------------------------------------------------
    |
    | Arithmetic curve:
    |   xp_to_next_level(level) = xp_increment_per_level * level
    |   total_xp_for_level(L)   = 50 * (L - 1) * L
    |
    | Trainer level is account progression. It is NOT creature evolution_stage
    | and NOT owned-creature level.
    |
    */

    'progression' => [
        'starting_level' => 1,
        'starting_xp' => 0,
        'xp_increment_per_level' => 100,
        'max_level' => 100,
        'formula' => 'xp_to_next = xp_increment_per_level * current_level; total_xp_for_level(L) = 50 * (L - 1) * L',
        'curve_sample_levels' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Creature Selection Entitlements
    |--------------------------------------------------------------------------
    |
    | Selection = acquiring a catalog creature into the personal collection.
    | Entitlements are keyed by the ORIGINAL evolution_stage at acquisition
    | (ledger), never by the creature's current stage after evolution.
    |
    | stage1Allowance = min(trainerLevel, allowance_cap)
    | stage2Allowance = max(0, trainerLevel - (unlock_level - allowance_at_unlock))
    |                 = max(0, trainerLevel - 9) with defaults below
    |
    */

    'selection' => [
        'stage_1' => [
            'unlock_level' => 1,
            'allowance_cap' => 10,
        ],
        'stage_2' => [
            'unlock_level' => 10,
            'allowance_at_unlock' => 1,
            'increment_per_level' => 1,
            'defined_through_level' => 20,
        ],
        'stage_3' => [
            'unlock_level' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Battle Eligibility & Rewards (no battle engine yet)
    |--------------------------------------------------------------------------
    */

    'battle' => [
        'pve_min_trainer_level' => 1,
        'pvp_min_trainer_level' => 6,
        'rewards' => [
            'pve' => [
                'win' => 10,
                'loss' => 5,
            ],
            'pvp' => [
                'win' => 20,
                'loss' => 10,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Starter Creatures
    |--------------------------------------------------------------------------
    |
    | Stage-1 base forms only. Selecting a starter consumes the first stage-1
    | selection entitlement.
    |
    */

    'starters' => [
        'creature_slugs' => ['bulbasaur', 'charmander', 'squirtle', 'pikachu', 'eevee'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */

    'features' => [
        'auth' => true,
        'profiles' => true,
        'collection' => true,
        'starter_selection' => true,
        'creature_selection' => true,
        'pve' => false,
        'pvp' => false,
        'evolution_execution' => false,
        'multiplayer' => false,
        'rankings' => false,
        'achievements' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Avatars
    |--------------------------------------------------------------------------
    */

    'avatar' => [
        'disk' => env('PROFILES_DISK', 'profiles_local'),
        'max_upload_kb' => 2048,
        'max_dimension' => 1024,
        'min_dimension' => 32,
        'output_dimension' => 512,
        'allowed_extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'allowed_mime_types' => ['image/jpeg', 'image/png', 'image/webp'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend URLs used in emails
    |--------------------------------------------------------------------------
    */

    'frontend' => [
        'url' => $frontendUrl,
        'email_verified_url' => env('FRONTEND_EMAIL_VERIFIED_URL', $frontendUrl.'/verify-email'),
        'reset_password_url' => env('FRONTEND_PASSWORD_RESET_URL', $frontendUrl.'/reset-password'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (attempts per minute)
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'login' => 5,
        'register' => 10,
        'password_recovery' => 5,
        'email_verification' => 6,
    ],

];
