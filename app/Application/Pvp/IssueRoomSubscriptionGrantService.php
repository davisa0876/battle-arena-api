<?php

namespace App\Application\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use App\Models\User;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

final class IssueRoomSubscriptionGrantService
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly PvpLobbyService $lobbies,
    ) {}

    /**
     * @return array{grant:string, expires_in:int, room_id:string}
     */
    public function issue(User $user, string $roomId): array
    {
        $room = PvpRoom::query()->find($roomId);
        if ($room === null || ! $room->isOpen()) {
            throw PvpLobbyException::notFound();
        }

        $isMember = PvpRoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isMember) {
            throw PvpLobbyException::notFound();
        }

        // Touch getRoom path to expire stale pending invites when needed.
        $this->lobbies->getRoom($user, $room->id);
        $room->refresh();
        if (! $room->isOpen()) {
            throw PvpLobbyException::roomClosed();
        }

        $ttl = (int) config('pvp.room_grant.ttl_seconds');
        $prefix = (string) config('pvp.room_grant.redis_prefix');
        $bytes = (int) config('pvp.room_grant.bytes');
        $connection = (string) config('pvp.room_grant.redis_connection');

        $grant = rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
        $key = $prefix.hash('sha256', $grant);
        $payload = json_encode([
            'user_id' => (int) $user->id,
            'room_id' => $room->id,
            'room_version' => (int) $room->version,
            'issued_at' => Carbon::now()->toIso8601String(),
        ], JSON_THROW_ON_ERROR);

        try {
            $client = $this->redis->connection($connection)->client();
            $set = $client->set($key, $payload, 'EX', $ttl, 'NX');
        } catch (Throwable $e) {
            throw PvpLobbyException::grantUnavailable();
        }

        $ok = $set === true || $set === 'OK' || (is_object($set) && (string) $set === 'OK');
        if (! $ok) {
            throw new RuntimeException('Room grant could not be reserved.');
        }

        return [
            'grant' => $grant,
            'expires_in' => $ttl,
            'room_id' => $room->id,
        ];
    }
}
