<?php

namespace Tests\Feature\Auth;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class LogoutClearsPresenceTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    #[Test]
    public function test_logout_removes_user_from_presence_redis_keys(): void
    {
        $user = $this->makePlayer(['handle' => 'logoutpres'], [], ['level' => 6, 'total_xp' => 1500]);

        $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('srem')
            ->once()
            ->with('battle-arena:presence:online', (string) $user->id)
            ->andReturn(1);
        $connection->shouldReceive('hdel')
            ->once()
            ->with('battle-arena:presence:conns', (string) $user->id)
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->with('ws_tickets')
            ->andReturn($connection);
        $this->app->instance('redis', $factory);
        $this->app->instance(RedisFactory::class, $factory);

        $this->actingAs($user, 'web')
            ->withHeaders($this->webHeaders())
            ->postJson('/api/logout')
            ->assertOk();
    }
}
