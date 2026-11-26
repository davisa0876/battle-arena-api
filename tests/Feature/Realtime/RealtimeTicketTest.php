<?php

namespace Tests\Feature\Realtime;

use App\Application\Realtime\IssueRealtimeTicketService;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Connections\Connection as RedisConnection;
use Mockery;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class RealtimeTicketTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    /**
     * @return object{stored: list<array{0: string, 1: string}>}
     */
    private function bindTicketRedis(bool $setSucceeds = true, bool $throwOnClient = false): object
    {
        $client = new class($setSucceeds)
        {
            /** @var list<array{0: string, 1: string}> */
            public array $stored = [];

            public function __construct(private readonly bool $setSucceeds) {}

            public function set(string $key, string $value, string $ex, int $ttl, string $nx): ?string
            {
                $this->stored[] = [$key, $value];

                return $this->setSucceeds ? 'OK' : null;
            }
        };

        $connection = Mockery::mock(RedisConnection::class);
        if ($throwOnClient) {
            $connection->shouldReceive('client')->andThrow(new \RuntimeException('connection refused'));
        } else {
            $connection->shouldReceive('client')->andReturn($client);
        }

        $factory = Mockery::mock(RedisFactory::class);
        $factory->shouldReceive('connection')
            ->with('ws_tickets')
            ->andReturn($connection);

        $this->app->instance('redis', $factory);
        $this->app->instance(RedisFactory::class, $factory);
        $this->app->forgetInstance(IssueRealtimeTicketService::class);

        return $client;
    }

    public function test_unauthenticated_user_cannot_request_a_ticket(): void
    {
        $this->postJson('/api/realtime/tickets')->assertUnauthorized();
    }

    public function test_authenticated_web_session_receives_a_one_use_ticket(): void
    {
        $client = $this->bindTicketRedis();
        $user = $this->makePlayer();

        $response = $this->actingAs($user, 'web')
            ->withHeaders($this->webHeaders())
            ->postJson('/api/realtime/tickets')
            ->assertCreated()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonPath('data.expires_in', (int) config('realtime.ticket.ttl_seconds'))
            ->assertJsonPath('data.ws_url', (string) config('realtime.public_ws_url'));

        $ticket = $response->json('data.ticket');
        $this->assertIsString($ticket);
        $this->assertGreaterThanOrEqual(40, strlen($ticket));

        $this->assertCount(1, $client->stored);
        [$key, $payload] = $client->stored[0];
        $this->assertSame(
            config('realtime.ticket.redis_prefix').hash('sha256', $ticket),
            $key,
        );
        $this->assertStringNotContainsString($ticket, $payload);

        $decoded = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($user->id, $decoded['user_id']);
        $this->assertArrayNotHasKey('ticket', $decoded);
    }

    public function test_authenticated_mobile_bearer_receives_a_one_use_ticket(): void
    {
        $client = $this->bindTicketRedis();
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/realtime/tickets')
            ->assertCreated()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonStructure(['data' => ['ticket', 'expires_in', 'ws_url']]);

        $this->assertCount(1, $client->stored);
    }

    public function test_ticket_issuance_is_rate_limited(): void
    {
        $this->bindTicketRedis();
        $user = $this->makePlayer();
        $limit = (int) config('realtime.rate_limit_per_minute');
        $token = $user->createToken('rate-limit')->plainTextToken;

        for ($i = 0; $i < $limit; $i++) {
            $this->withToken($token)
                ->postJson('/api/realtime/tickets')
                ->assertCreated();
        }

        $this->withToken($token)
            ->postJson('/api/realtime/tickets')
            ->assertStatus(429);
    }

    public function test_redis_failure_rejects_issuance_safely(): void
    {
        $this->bindTicketRedis(throwOnClient: true);
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/realtime/tickets')
            ->assertStatus(503)
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonPath('message', __('api.realtime.ticket_unavailable'));
    }

    public function test_service_stores_hashed_ticket_only(): void
    {
        $client = $this->bindTicketRedis();
        $user = $this->makePlayer();

        $result = $this->app->make(IssueRealtimeTicketService::class)->issue($user);

        $this->assertSame(
            config('realtime.ticket.redis_prefix').hash('sha256', $result->ticket),
            $client->stored[0][0],
        );
        $this->assertStringNotContainsString($result->ticket, $client->stored[0][1]);
    }
}
