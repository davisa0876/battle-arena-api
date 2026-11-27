<?php

namespace App\Application\Pvp;

use App\Infrastructure\Realtime\LambdaRealtimeNotifier;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Str;
use Throwable;

/**
 * Best-effort after-commit notification publisher.
 * Clients must rehydrate via REST; neither Redis pub/sub nor async Lambda invokes are durable.
 */
final class PvpRealtimePublisher
{
    public function __construct(
        private readonly RedisFactory $redis,
        // Non-nullable on purpose: the container passes null for unbound nullable defaults.
        private readonly LambdaRealtimeNotifier $lambda = new LambdaRealtimeNotifier,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $type, array $payload, ?int $targetUserId = null, ?string $targetRoomId = null): void
    {
        $envelope = [
            'v' => 1,
            'type' => $type,
            'event_id' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
            'target_user_id' => $targetUserId,
            'target_room_id' => $targetRoomId,
            'payload' => $payload,
        ];

        try {
            if (config('pvp.realtime.driver', 'redis') === 'lambda') {
                $this->lambda->dispatch($envelope);

                return;
            }

            $connection = (string) config('pvp.room_grant.redis_connection', 'ws_tickets');
            $channel = (string) config('pvp.realtime.channel');
            $client = $this->redis->connection($connection)->client();
            $client->publish($channel, json_encode($envelope, JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            // Fail soft for notifications; REST remains authoritative.
            report_if(config('pvp.realtime.driver') === 'lambda', $exception);
        }
    }
}
