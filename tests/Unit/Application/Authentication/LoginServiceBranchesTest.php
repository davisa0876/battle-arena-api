<?php

namespace Tests\Unit\Application\Authentication;

use App\Application\Authentication\LoginService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class LoginServiceBranchesTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    #[Test]
    public function test_start_session_without_device_or_session_fails(): void
    {
        $user = $this->makePlayer();
        $service = $this->app->make(LoginService::class);
        $request = Request::create('/api/login', 'POST');

        $this->expectException(ValidationException::class);
        $service->startAuthenticatedSession($user, null, $request);
    }

    #[Test]
    public function test_logout_with_bearer_token_clears_presence(): void
    {
        $user = $this->makePlayer();
        $plain = $user->createToken('phone')->plainTextToken;

        $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('srem')->once()->andReturn(1);
        $connection->shouldReceive('hdel')->once()->andReturn(1);
        $factory = Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->andReturn($connection);
        $this->app->instance('redis', $factory);
        $this->app->instance(\Illuminate\Contracts\Redis\Factory::class, $factory);

        $request = Request::create('/api/logout', 'POST');
        $request->headers->set('Authorization', 'Bearer '.$plain);

        $this->app->make(LoginService::class)->logout($user->fresh(), $request);
        $this->assertSame(0, $user->tokens()->count());
    }
}
