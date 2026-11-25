<?php

namespace App\Application\Authentication\DTOs;

use App\Models\User;

/**
 * `token` is set for mobile (bearer) clients and null for cookie-session web clients.
 */
final readonly class AuthenticationResult
{
    public function __construct(
        public User $user,
        public ?string $token,
    ) {}

    public function authMode(): string
    {
        return $this->token === null ? 'session' : 'token';
    }
}
