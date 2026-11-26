<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use App\Application\Battles\BattleRewardService;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpBattleEvent;
use App\Domain\Pvp\Models\PvpBattleReward;
use App\Domain\Pvp\Models\PvpRoom;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Shared terminal persistence for combat KO and non-combat endings (forfeit/timeout).
 */
final class PvpBattleTerminalService
{
    public function __construct(
        private readonly BattleRewardService $rewards,
        private readonly PvpRealtimePublisher $publisher,
    ) {}

    /**
     * Apply combat KO terminal fields already set by the turn engine, then award normal XP and close room.
     *
     * @return list<array{user_id:int, outcome:string, xp_awarded:int}>|null
     */
    public function finalizeCombatRewards(PvpBattle $battle): ?array
    {
        if ($battle->rewards_processed) {
            return $this->existingRewardPayload($battle);
        }

        if ($battle->winner_user_id === null || $battle->loser_user_id === null) {
            return null;
        }

        $battle->end_reason = PvpBattle::END_REASON_COMBAT;
        $battle->status = PvpBattle::STATUS_FINISHED;
        $battle->phase = PvpBattle::PHASE_FINISHED;
        $battle->finished_at ??= Carbon::now();
        $battle->action_deadline_at = null;
        $battle->deadline_actor_user_id = null;
        $battle->deadline_phase = null;
        $battle->deadline_version = null;
        $battle->pending_attack_snapshot = null;
        $battle->save();

        return $this->awardCombatXpAndClose($battle);
    }

    /**
     * Terminalize an active battle for forfeit or timeout with proposed non-combat XP (default 0/0).
     *
     * @return list<array{user_id:int, outcome:string, xp_awarded:int}>
     */
    public function finalizeNonCombat(
        PvpBattle $battle,
        string $endReason,
        int $winnerUserId,
        int $loserUserId,
        ?int $forfeitingUserId = null,
        ?int $timedOutUserId = null,
        array $eventPayload = [],
    ): array {
        if ($battle->rewards_processed || $battle->isFinished()) {
            return $this->existingRewardPayload($battle) ?? [];
        }

        $sequence = (int) $battle->last_event_sequence + 1;
        $battle->status = PvpBattle::STATUS_FINISHED;
        $battle->phase = PvpBattle::PHASE_FINISHED;
        $battle->version = (int) $battle->version + 1;
        $battle->winner_user_id = $winnerUserId;
        $battle->loser_user_id = $loserUserId;
        $battle->end_reason = $endReason;
        $battle->forfeiting_user_id = $forfeitingUserId;
        $battle->timed_out_user_id = $timedOutUserId;
        $battle->pending_attack_snapshot = null;
        $battle->action_deadline_at = null;
        $battle->deadline_actor_user_id = null;
        $battle->deadline_phase = null;
        $battle->deadline_version = null;
        $battle->finished_at = Carbon::now();
        $battle->last_event_sequence = $sequence;
        $battle->save();

        PvpBattleEvent::query()->create([
            'battle_id' => $battle->id,
            'sequence' => $sequence,
            'type' => $endReason === PvpBattle::END_REASON_FORFEIT ? 'battle_forfeited' : 'battle_timed_out',
            'actor' => null,
            'target' => null,
            'payload' => array_merge([
                'end_reason' => $endReason,
                'winner_user_id' => $winnerUserId,
                'loser_user_id' => $loserUserId,
                'forfeiting_user_id' => $forfeitingUserId,
                'timed_out_user_id' => $timedOutUserId,
            ], $eventPayload),
            'occurred_at' => Carbon::now(),
        ]);

        return $this->recordNonCombatRewardsAndClose($battle, $winnerUserId, $loserUserId);
    }

    /**
     * @return list<array{user_id:int, outcome:string, xp_awarded:int}>
     */
    private function awardCombatXpAndClose(PvpBattle $battle): array
    {
        $winner = User::query()->with('progress')->findOrFail((int) $battle->winner_user_id);
        $loser = User::query()->with('progress')->findOrFail((int) $battle->loser_user_id);

        $winnerResult = $this->rewards->awardForCompletedMatch($winner, [
            'match_id' => (string) $battle->id,
            'mode' => BattleRewardPolicy::MODE_PVP,
            'outcome' => BattleRewardPolicy::OUTCOME_WIN,
            'verified' => true,
        ]);
        $loserResult = $this->rewards->awardForCompletedMatch($loser, [
            'match_id' => (string) $battle->id,
            'mode' => BattleRewardPolicy::MODE_PVP,
            'outcome' => BattleRewardPolicy::OUTCOME_LOSS,
            'verified' => true,
        ]);

        PvpBattleReward::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'user_id' => $winner->id,
            'outcome' => BattleRewardPolicy::OUTCOME_WIN,
            'xp_awarded' => (int) $winnerResult->event->amount,
            'trainer_levels_gained' => (int) $winnerResult->levelsGained,
            'idempotency_key' => sprintf('battle:pvp:%s:%d', $battle->id, $winner->id),
        ]);
        PvpBattleReward::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'user_id' => $loser->id,
            'outcome' => BattleRewardPolicy::OUTCOME_LOSS,
            'xp_awarded' => (int) $loserResult->event->amount,
            'trainer_levels_gained' => (int) $loserResult->levelsGained,
            'idempotency_key' => sprintf('battle:pvp:%s:%d', $battle->id, $loser->id),
        ]);

        $battle->rewards_processed = true;
        $battle->save();
        $this->closeRoomAfterBattle($battle);

        return [
            ['user_id' => (int) $winner->id, 'outcome' => 'win', 'xp_awarded' => (int) $winnerResult->event->amount],
            ['user_id' => (int) $loser->id, 'outcome' => 'loss', 'xp_awarded' => (int) $loserResult->event->amount],
        ];
    }

    /**
     * Proposed MVP: 0 XP each — do not create PlayerXpEvent rows.
     *
     * @return list<array{user_id:int, outcome:string, xp_awarded:int}>
     */
    private function recordNonCombatRewardsAndClose(PvpBattle $battle, int $winnerUserId, int $loserUserId): array
    {
        $winXp = max(0, (int) config('pvp.noncombat.win_xp', 0));
        $lossXp = max(0, (int) config('pvp.noncombat.loss_xp', 0));

        // Zero XP by design: skip BattleRewardService to avoid progression events.
        if ($winXp > 0 || $lossXp > 0) {
            // Reserved for future approved non-zero policy; still avoid accidental +20/+10.
            throw new \RuntimeException('Non-zero noncombat PvP XP requires an approved BattleRewardService path.');
        }

        PvpBattleReward::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'user_id' => $winnerUserId,
            'outcome' => BattleRewardPolicy::OUTCOME_WIN,
            'xp_awarded' => 0,
            'trainer_levels_gained' => 0,
            'idempotency_key' => sprintf('battle:pvp:%s:%d', $battle->id, $winnerUserId),
        ]);
        PvpBattleReward::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'user_id' => $loserUserId,
            'outcome' => BattleRewardPolicy::OUTCOME_LOSS,
            'xp_awarded' => 0,
            'trainer_levels_gained' => 0,
            'idempotency_key' => sprintf('battle:pvp:%s:%d', $battle->id, $loserUserId),
        ]);

        $battle->rewards_processed = true;
        $battle->save();
        $this->closeRoomAfterBattle($battle);

        return [
            ['user_id' => $winnerUserId, 'outcome' => 'win', 'xp_awarded' => 0],
            ['user_id' => $loserUserId, 'outcome' => 'loss', 'xp_awarded' => 0],
        ];
    }

    private function closeRoomAfterBattle(PvpBattle $battle): void
    {
        $room = PvpRoom::query()->whereKey($battle->room_id)->lockForUpdate()->first();
        if ($room === null || $room->status !== PvpRoom::STATUS_IN_BATTLE) {
            return;
        }

        $room->status = PvpRoom::STATUS_CLOSED;
        $room->closed_reason = PvpRoom::CLOSED_BATTLE_COMPLETED;
        $room->closed_at = Carbon::now();
        $room->version = (int) $room->version + 1;
        $room->save();

        $this->publisher->publish('pvp.room.changed', [
            'room_id' => $room->id,
            'version' => (int) $room->version,
        ], targetRoomId: $room->id);
    }

    /**
     * @return list<array{user_id:int, outcome:string, xp_awarded:int}>|null
     */
    private function existingRewardPayload(PvpBattle $battle): ?array
    {
        $rows = PvpBattleReward::query()->where('battle_id', $battle->id)->get();
        if ($rows->isEmpty()) {
            return null;
        }

        return $rows->map(fn (PvpBattleReward $r) => [
            'user_id' => (int) $r->user_id,
            'outcome' => $r->outcome,
            'xp_awarded' => (int) $r->xp_awarded,
        ])->values()->all();
    }
}
