<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Dependencies are faked at the facade level so the outcome is deterministic
 * (the suite runs on in-memory SQLite without a Redis server).
 */
class ReadyEndpointTest extends TestCase
{
    private function fakeDatabase(bool $healthy): void
    {
        $connection = Mockery::mock(Connection::class);
        $db = DB::partialMock();

        if ($healthy) {
            $connection->shouldReceive('getPdo')->once()->andReturn(new \stdClass);
            $db->shouldReceive('connection')->once()->andReturn($connection);
            $db->shouldReceive('select')->once()->with('select 1')->andReturn([(object) ['?column?' => 1]]);

            return;
        }

        $db->shouldReceive('connection')->once()->andThrow(new \PDOException('SQLSTATE[08006] connection refused'));
        $db->shouldReceive('select')->never();
    }

    private function fakeRedis(bool $healthy): void
    {
        $connection = Mockery::mock(RedisConnection::class);

        if ($healthy) {
            $connection->shouldReceive('ping')->once()->andReturn(true);
        } else {
            $connection->shouldReceive('ping')->once()->andThrow(new \RuntimeException('Connection refused'));
        }

        Redis::shouldReceive('connection')->once()->andReturn($connection);
    }

    #[Test]
    public function test_returns_200_when_postgres_and_redis_are_healthy(): void
    {
        $this->fakeDatabase(true);
        $this->fakeRedis(true);

        $this->getJson('/api/ready')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ready',
                'service' => 'battle-arena-api',
                'checks' => ['postgres' => 'ok', 'redis' => 'ok'],
            ]);
    }

    #[Test]
    public function test_returns_503_when_postgres_fails_but_redis_is_ok(): void
    {
        $this->fakeDatabase(false);
        $this->fakeRedis(true);

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'service' => 'battle-arena-api',
                'checks' => ['postgres' => 'fail', 'redis' => 'ok'],
            ]);
    }

    #[Test]
    public function test_returns_503_when_postgres_query_fails_after_connecting(): void
    {
        $connection = Mockery::mock(Connection::class);
        $db = DB::partialMock();
        $connection->shouldReceive('getPdo')->once()->andReturn(new \stdClass);
        $db->shouldReceive('connection')->once()->andReturn($connection);
        $db->shouldReceive('select')->once()->with('select 1')->andThrow(new \RuntimeException('query failed'));
        $this->fakeRedis(true);

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.postgres', 'fail')
            ->assertJsonPath('checks.redis', 'ok');
    }

    #[Test]
    public function test_returns_503_when_redis_fails_but_postgres_is_ok(): void
    {
        $this->fakeDatabase(true);
        $this->fakeRedis(false);

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'service' => 'battle-arena-api',
                'checks' => ['postgres' => 'ok', 'redis' => 'fail'],
            ]);
    }

    #[Test]
    public function test_returns_503_when_redis_connection_cannot_be_created(): void
    {
        $this->fakeDatabase(true);
        Redis::shouldReceive('connection')->once()->andThrow(new \RuntimeException('No such host'));

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertJsonPath('checks.postgres', 'ok')
            ->assertJsonPath('checks.redis', 'fail');
    }

    #[Test]
    public function test_returns_503_when_both_dependencies_fail(): void
    {
        $this->fakeDatabase(false);
        $this->fakeRedis(false);

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'service' => 'battle-arena-api',
                'checks' => ['postgres' => 'fail', 'redis' => 'fail'],
            ]);
    }

    #[Test]
    public function test_failure_details_are_not_leaked_in_the_response(): void
    {
        $this->fakeDatabase(false);
        $this->fakeRedis(false);

        $content = $this->getJson('/api/ready')->getContent();

        $this->assertStringNotContainsString('SQLSTATE', (string) $content);
        $this->assertStringNotContainsString('Connection refused', (string) $content);
    }
}
