<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Force-removes a trainer from the WS online SET (and connection refcount hash).
 * Used on logout so discovery does not keep showing them after the client session ends,
 * even if a realtime disconnect race left Redis dirty.
 */
final class PvpPresenceClearService
{
    public function __construct(
        private readonly RedisFactory $redis,
    ) {}

    public function clearForUser(int $userId): void
    {
        if ($userId < 1) {
            return;
        }

        $connection = (string) config('pvp.presence.redis_connection', 'ws_tickets');
        $onlineKey = (string) config('pvp.presence.online_set_key', 'battle-arena:presence:online');
        $connHashKey = (string) config('pvp.presence.conn_hash_key', 'battle-arena:presence:conns');
        $field = (string) $userId;

        try {
            $client = $this->redis->connection($connection);
            $client->srem($onlineKey, $field);
            $client->hdel($connHashKey, $field);
        } catch (Throwable $e) {
            Log::warning('pvp.presence.clear_failed', [
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
