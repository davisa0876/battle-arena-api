<?php

namespace Tests\Unit\Application\Pvp;

use App\Application\Pvp\PvpPresenceClearService;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PvpPresenceClearServiceTest extends TestCase
{
    #[Test]
    public function test_ignores_non_positive_user_ids(): void
    {
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->never();

        (new PvpPresenceClearService($factory))->clearForUser(0);
        (new PvpPresenceClearService($factory))->clearForUser(-3);
    }

    #[Test]
    public function test_removes_online_set_and_conn_hash_fields(): void
    {
        $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('srem')
            ->once()
            ->with('battle-arena:presence:online', '42')
            ->andReturn(1);
        $connection->shouldReceive('hdel')
            ->once()
            ->with('battle-arena:presence:conns', '42')
            ->andReturn(1);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->once()
            ->with('ws_tickets')
            ->andReturn($connection);

        (new PvpPresenceClearService($factory))->clearForUser(42);
    }

    #[Test]
    public function test_logs_warning_when_redis_throws(): void
    {
        Log::spy();

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->once()
            ->andThrow(new \RuntimeException('redis down'));

        (new PvpPresenceClearService($factory))->clearForUser(7);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'pvp.presence.clear_failed'
                    && $context['user_id'] === 7
                    && str_contains((string) $context['message'], 'redis down');
            });
    }
}
