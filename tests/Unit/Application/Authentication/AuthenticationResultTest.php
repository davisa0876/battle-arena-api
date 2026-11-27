<?php

namespace Tests\Unit\Application\Authentication;

use App\Application\Authentication\DTOs\AuthenticationResult;
use App\Models\User;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationResultTest extends TestCase
{
    #[Test]
    public function test_auth_mode_is_session_when_token_is_null(): void
    {
        $user = new User;
        $result = new AuthenticationResult($user, null);

        $this->assertSame('session', $result->authMode());
        $this->assertSame($user, $result->user);
        $this->assertNull($result->token);
    }

    #[Test]
    public function test_auth_mode_is_token_when_bearer_present(): void
    {
        $user = new User;
        $result = new AuthenticationResult($user, 'plain-token');

        $this->assertSame('token', $result->authMode());
        $this->assertSame('plain-token', $result->token);
    }
}
