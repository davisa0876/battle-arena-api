<?php

namespace Tests\Unit\Application\Pvp;

use App\Application\Pvp\PvpRealtimePublisher;
use App\Infrastructure\Realtime\LambdaRealtimeNotifier;
use Aws\CommandInterface;
use Aws\Lambda\Exception\LambdaException;
use Aws\Lambda\LambdaClient;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PvpRealtimePublisherTest extends TestCase
{
    private function lambdaClient(MockHandler $handler): LambdaClient
    {
        return new LambdaClient([
            'version' => '2015-03-31',
            'region' => 'us-east-1',
            'credentials' => false,
            'handler' => $handler,
        ]);
    }

    #[Test]
    public function test_redis_driver_publishes_envelope_to_channel(): void
    {
        config(['pvp.realtime.driver' => 'redis', 'pvp.realtime.channel' => 'chan:v1']);

        $client = Mockery::mock();
        $client->shouldReceive('publish')
            ->once()
            ->withArgs(function (string $channel, string $message): bool {
                $envelope = json_decode($message, true);

                return $channel === 'chan:v1'
                    && $envelope['type'] === 'pvp.room.changed'
                    && $envelope['target_room_id'] === 'room-1';
            })
            ->andReturn(1);
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('client')->andReturn($client);
        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->with('ws_tickets')->andReturn($connection);

        $handler = new MockHandler;
        $publisher = new PvpRealtimePublisher($factory, new LambdaRealtimeNotifier($this->lambdaClient($handler)));
        $publisher->publish('pvp.room.changed', ['room_id' => 'room-1', 'version' => 2], null, 'room-1');

        $this->assertNull($handler->getLastCommand());
    }

    #[Test]
    public function test_lambda_driver_async_invokes_realtime_function_with_same_envelope(): void
    {
        config([
            'pvp.realtime.driver' => 'lambda',
            'pvp.realtime.lambda.function' => 'battle-arena-realtime',
        ]);

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')->never();

        $handler = new MockHandler;
        $handler->append(new Result(['StatusCode' => 202]));
        $publisher = new PvpRealtimePublisher($factory, new LambdaRealtimeNotifier($this->lambdaClient($handler)));
        $publisher->publish('pvp.invitation.changed', ['invitation_id' => 'inv-1'], 42);

        $command = $handler->getLastCommand();
        $this->assertInstanceOf(CommandInterface::class, $command);
        $this->assertSame('Invoke', $command->getName());
        $this->assertSame('battle-arena-realtime', $command['FunctionName']);
        $this->assertSame('Event', $command['InvocationType']);

        $envelope = json_decode((string) $command['Payload'], true);
        $this->assertSame(1, $envelope['v']);
        $this->assertSame('pvp.invitation.changed', $envelope['type']);
        $this->assertSame(42, $envelope['target_user_id']);
        $this->assertNull($envelope['target_room_id']);
        $this->assertSame(['invitation_id' => 'inv-1'], $envelope['payload']);
        $this->assertNotEmpty($envelope['event_id']);
    }

    #[Test]
    public function test_lambda_driver_failures_never_break_the_request(): void
    {
        config(['pvp.realtime.driver' => 'lambda']);

        $handler = new MockHandler;
        $handler->append(function (CommandInterface $command) {
            return new LambdaException('throttled', $command);
        });

        $publisher = new PvpRealtimePublisher(
            Mockery::mock(RedisFactory::class),
            new LambdaRealtimeNotifier($this->lambdaClient($handler)),
        );
        $publisher->publish('pvp.battle.changed', ['room_id' => 'r'], null, 'r');

        $this->assertSame(0, $handler->count());
    }

    #[Test]
    public function test_container_resolves_publisher_with_lazy_lambda_notifier(): void
    {
        $publisher = $this->app->make(PvpRealtimePublisher::class);

        $notifier = (new \ReflectionProperty($publisher, 'lambda'))->getValue($publisher);
        $this->assertInstanceOf(LambdaRealtimeNotifier::class, $notifier);
    }

    #[Test]
    public function test_container_resolved_publisher_uses_lambda_when_configured(): void
    {
        config(['pvp.realtime.driver' => 'lambda', 'pvp.realtime.lambda.function' => 'fn']);
        $handler = new MockHandler;
        $handler->append(new Result(['StatusCode' => 202]));
        $this->app->instance(LambdaRealtimeNotifier::class, new LambdaRealtimeNotifier($this->lambdaClient($handler)));

        $this->app->make(PvpRealtimePublisher::class)->publish('pvp.room.changed', ['room_id' => 'r', 'version' => 1], null, 'r');

        $this->assertSame('fn', $handler->getLastCommand()['FunctionName']);
    }
}
