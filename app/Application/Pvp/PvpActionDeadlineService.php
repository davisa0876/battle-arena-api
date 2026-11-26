<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use App\Domain\Pvp\Models\PvpBattle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PvpActionDeadlineService
{
    public function __construct(
        private readonly PvpBattleTerminalService $terminal,
        private readonly PvpRealtimePublisher $publisher,
    ) {}

    public function timeoutSeconds(): int
    {
        return max(30, min(3600, (int) config('pvp.turn_timeout.seconds', 300)));
    }

    public function adjudicationEnabled(): bool
    {
        return (bool) config('pvp.turn_timeout.enabled', false);
    }

    public function refreshDeadline(PvpBattle $battle, ?Carbon $now = null): void
    {
        if ($battle->isFinished()) {
            $this->clearDeadline($battle);

            return;
        }

        $now ??= Carbon::now('UTC');
        $actorId = $battle->phase === PvpBattle::PHASE_AWAITING_DEFENSE
            ? (int) $battle->current_defender_user_id
            : (int) $battle->current_attacker_user_id;

        $battle->action_deadline_at = $now->copy()->addSeconds($this->timeoutSeconds());
        $battle->deadline_actor_user_id = $actorId;
        $battle->deadline_phase = (string) $battle->phase;
        $battle->deadline_version = (int) $battle->version;
        $battle->save();
    }

    public function clearDeadline(PvpBattle $battle): void
    {
        $battle->action_deadline_at = null;
        $battle->deadline_actor_user_id = null;
        $battle->deadline_phase = null;
        $battle->deadline_version = null;
        $battle->save();
    }

    public function isOverdue(PvpBattle $battle, ?Carbon $now = null): bool
    {
        if ($battle->isFinished() || $battle->action_deadline_at === null) {
            return false;
        }

        $now ??= Carbon::now('UTC');

        return $battle->action_deadline_at->lessThanOrEqualTo($now);
    }

    /**
     * If adjudication is enabled and the locked battle is overdue, finish it once.
     *
     * @return list<array{user_id:int, outcome:string, xp_awarded:int}>|null rewards when timed out
     */
    public function resolveIfOverdue(PvpBattle $battle, ?Carbon $now = null): ?array
    {
        if (! $this->adjudicationEnabled() || ! $this->isOverdue($battle, $now) || $battle->isFinished()) {
            return null;
        }

        $timedOutUserId = (int) ($battle->deadline_actor_user_id
            ?? ($battle->phase === PvpBattle::PHASE_AWAITING_DEFENSE
                ? $battle->current_defender_user_id
                : $battle->current_attacker_user_id));

        $winnerUserId = (int) $battle->host_user_id === $timedOutUserId
            ? (int) $battle->guest_user_id
            : (int) $battle->host_user_id;

        return $this->terminal->finalizeNonCombat(
            $battle,
            PvpBattle::END_REASON_TIMEOUT,
            $winnerUserId,
            $timedOutUserId,
            forfeitingUserId: null,
            timedOutUserId: $timedOutUserId,
            eventPayload: [
                'deadline_at' => optional($battle->action_deadline_at)?->toIso8601String(),
                'deadline_phase' => $battle->deadline_phase,
                'deadline_version' => $battle->deadline_version,
            ],
        );
    }

    /**
     * Bounded batch of due active battles. Safe to retry; uses row locks.
     *
     * @return array{processed:int, timed_out:int, skipped:int}
     */
    public function adjudicateDueBatch(int $limit = 50, ?Carbon $now = null): array
    {
        if (! $this->adjudicationEnabled()) {
            return ['processed' => 0, 'timed_out' => 0, 'skipped' => 0];
        }

        $now ??= Carbon::now('UTC');
        $limit = max(1, min(200, $limit));
        $processed = 0;
        $timedOut = 0;
        $skipped = 0;

        $ids = PvpBattle::query()
            ->where('status', PvpBattle::STATUS_ACTIVE)
            ->whereNotNull('action_deadline_at')
            ->where('action_deadline_at', '<=', $now)
            ->orderBy('action_deadline_at')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $battleId) {
            $processed++;
            $changed = DB::transaction(function () use ($battleId, $now): bool {
                $battle = PvpBattle::query()->whereKey($battleId)->lockForUpdate()->first();
                if ($battle === null || $battle->isFinished()) {
                    return false;
                }
                if (! $this->isOverdue($battle, $now)) {
                    return false;
                }

                $this->resolveIfOverdue($battle, $now);

                return true;
            });

            if ($changed) {
                $timedOut++;
                $battle = PvpBattle::query()->whereKey($battleId)->first();
                if ($battle !== null) {
                    $this->publisher->publish('pvp.battle.changed', [
                        'room_id' => $battle->room_id,
                        'battle_id' => $battle->id,
                        'version' => (int) $battle->version,
                        'last_event_sequence' => (int) $battle->last_event_sequence,
                    ], targetRoomId: (string) $battle->room_id);
                }
            } else {
                $skipped++;
            }
        }

        return ['processed' => $processed, 'timed_out' => $timedOut, 'skipped' => $skipped];
    }
}
