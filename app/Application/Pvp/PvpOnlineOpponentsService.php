<?php

namespace App\Application\Pvp;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Players\Models\PlayerProfile;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use App\Models\User;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        private readonly PvpActionDeadlineService $deadlines,
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

        // Opportunistic cleanup so abandoned battles do not hide trainers forever when
        // the scheduler is slow/offline (local docker has no schedule:work by default).
        if ($this->deadlines->adjudicationEnabled()) {
            $this->deadlines->adjudicateDueBatch(min(20, (int) config('pvp.turn_timeout.batch_size', 50)));
        }
        $this->closeOrphanInBattleRooms($candidateIds);

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

        // Align with createRoom: waiting_for_guest hosts are replaceable (supersede),
        // so they must still appear online. Only committed lobbies/battles are busy.
        $busy = PvpRoomMember::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('left_at')
            ->whereHas('room', fn ($q) => $q->whereIn('status', [
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

    /**
     * Close rooms stuck in in_battle without an active battle so presence lists recover.
     *
     * @param  list<int>  $userIds
     */
    private function closeOrphanInBattleRooms(array $userIds): void
    {
        if ($userIds === []) {
            return;
        }

        $roomIds = PvpRoomMember::query()
            ->whereIn('user_id', $userIds)
            ->whereNull('left_at')
            ->whereHas('room', fn ($q) => $q->where('status', PvpRoom::STATUS_IN_BATTLE))
            ->pluck('room_id')
            ->unique()
            ->values()
            ->all();

        foreach ($roomIds as $roomId) {
            DB::transaction(function () use ($roomId): void {
                $room = PvpRoom::query()->whereKey($roomId)->lockForUpdate()->first();
                if ($room === null || $room->status !== PvpRoom::STATUS_IN_BATTLE) {
                    return;
                }

                $hasActiveBattle = PvpBattle::query()
                    ->where('room_id', $room->id)
                    ->where('status', PvpBattle::STATUS_ACTIVE)
                    ->exists();

                if ($hasActiveBattle) {
                    return;
                }

                $room->status = PvpRoom::STATUS_CLOSED;
                $room->closed_reason = PvpRoom::CLOSED_BATTLE_COMPLETED;
                $room->closed_at = Carbon::now();
                $room->version = (int) $room->version + 1;
                $room->save();

                PvpRoomMember::query()
                    ->where('room_id', $room->id)
                    ->whereNull('left_at')
                    ->update(['left_at' => Carbon::now()]);
            });
        }
    }
}
