<?php

namespace App\Application\Realtime;

use App\Application\Realtime\DTOs\RealtimeTicketResult;
use App\Models\User;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Carbon;
use RuntimeException;

final class IssueRealtimeTicketService
{
    public function __construct(
        private readonly RedisFactory $redis,
    ) {}

    public function issue(User $user, ?string $sessionId = null, ?string $tokenId = null): RealtimeTicketResult
    {
        $ttl = (int) config('realtime.ticket.ttl_seconds');
        $prefix = (string) config('realtime.ticket.redis_prefix');
        $bytes = (int) config('realtime.ticket.bytes');
        $connection = (string) config('realtime.ticket.redis_connection');

        $ticket = rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        $hash = hash('sha256', $ticket);
        $key = $prefix.$hash;

        $payload = [
            'user_id' => (int) $user->getAuthIdentifier(),
            'issued_at' => Carbon::now()->toIso8601String(),
        ];

        if ($sessionId !== null && $sessionId !== '') {
            $payload['session_id'] = $sessionId;
        }

        if ($tokenId !== null && $tokenId !== '') {
            $payload['token_id'] = $tokenId;
        }

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        try {
            $client = $this->redis->connection($connection)->client();
            $set = $client->set($key, $encoded, 'EX', $ttl, 'NX');
        } catch (\Throwable $e) {
            throw new RuntimeException('Realtime ticket store unavailable.', 0, $e);
        }

        // Predis returns a Status('OK') or null when NX fails; phpredis returns true/false/null.
        $ok = $set === true || $set === 'OK' || (is_object($set) && (string) $set === 'OK');
        if (! $ok) {
            throw new RuntimeException('Realtime ticket could not be reserved.');
        }

        return new RealtimeTicketResult(
            ticket: $ticket,
            expiresIn: $ttl,
            wsUrl: (string) config('realtime.public_ws_url'),
        );
    }
}
