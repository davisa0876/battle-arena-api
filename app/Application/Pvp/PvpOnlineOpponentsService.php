<?php

namespace App\Application\Pvp;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Players\Models\PlayerProfile;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use App\Models\User;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Lists PvP-eligible trainers currently connected to the realtime gateway.
 * Presence is owned by battle-arena-realtime (Redis SET): clients keep a WS open while
 * authenticated and PvP-level-unlocked on any screen — not only the PvP lobby.
 * Invite still uses POST /pvp/rooms (exact handle or pick from this list).
 */
final class PvpOnlineOpponentsService
{
    public function __construct(
        private readonly RedisFactory $redis,
        private readonly BattleEligibilityPolicy $eligibility,
    ) {}

    /**
     * @return list<array{id:int, handle:string, display_name:?string, avatar_url:?string, level:int}>
     */
    public function listFor(User $viewer): array
    {
        $onlineIds = $this->readOnlineUserIds();
        if ($onlineIds === []) {
            return [];
        }

        $viewerId = (int) $viewer->id;
        $candidateIds = array_values(array_filter(
            $onlineIds,
            static fn (int $id): bool => $id !== $viewerId,
        ));
        if ($candidateIds === []) {
            return [];
        }

        $busyIds = $this->busyUserIds($candidateIds);
        $minLevel = $this->eligibility->pvpMinTrainerLevel();
        $limit = max(1, (int) config('pvp.presence.max_results', 40));

        /** @var Collection<int, PlayerProfile> $profiles */
        $profiles = PlayerProfile::query()
            ->with(['user.progress'])
            ->whereIn('user_id', $candidateIds)
            ->orderBy('handle')
            ->get();

        $out = [];
        foreach ($profiles as $profile) {
            $user = $profile->user;
            if ($user === null) {
                continue;
            }
            $userId = (int) $user->id;
            if (isset($busyIds[$userId])) {
                continue;
            }
            $level = (int) ($user->progress?->level ?? 1);
            if (! $this->eligibility->canEnterPvp($level)) {
                continue;
            }
            if ($level < $minLevel) {
                continue;
            }

            $out[] = [
                'id' => $userId,
                'handle' => (string) $profile->handle,
                'display_name' => $profile->display_name ?? $profile->handle,
                'avatar_url' => $profile->hasAvatar()
                    ? url('/api/avatars/'.$userId)
                    : null,
                'level' => $level,
            ];

            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @return list<int>
     */
    private function readOnlineUserIds(): array
    {
        $key = (string) config('pvp.presence.online_set_key');
        $connection = (string) config('pvp.presence.redis_connection', 'ws_tickets');

        try {
            $raw = $this->redis->connection($connection)->smembers($key);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $id = (int) $value;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, true>
     */
    private function busyUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $busy = PvpRoomMember::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('left_at')
            ->whereHas('room', fn ($q) => $q->whereIn('status', [
                PvpRoom::STATUS_WAITING_FOR_GUEST,
                PvpRoom::STATUS_READY,
                PvpRoom::STATUS_IN_BATTLE,
            ]))
            ->pluck('user_id')
            ->all();

        $map = [];
        foreach ($busy as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }
}
