<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\DTOs\AuthenticationResult;

/**
 * Builds the `meta` block shared by register and login responses.
 */
final class AuthMeta
{
    /**
     * @return array{auth_mode: string, token?: string, token_type?: string}
     */
    public static function from(AuthenticationResult $result): array
    {
        $meta = ['auth_mode' => $result->authMode()];

        if ($result->token !== null) {
            $meta['token'] = $result->token;
            $meta['token_type'] = 'Bearer';
        }

        return $meta;
    }
}
