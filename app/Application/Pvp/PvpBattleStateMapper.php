<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use App\Application\Battles\PveRulesProvider;
use App\Domain\Battle\BattleEvent;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\Pvp\PvpAttackCommitment;
use App\Domain\Battle\Pvp\PvpBattlePhase;
use App\Domain\Battle\Pvp\PvpBattleState;
use App\Domain\Battle\Pvp\PvpTurnTransition;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpBattleCombatant;
use App\Domain\Pvp\Models\PvpBattleCombatantMove;
use App\Domain\Pvp\Models\PvpBattleEvent;
use Illuminate\Support\Carbon;

final class PvpBattleStateMapper
{
    public function __construct(
        private readonly PveRulesProvider $rulesProvider,
    ) {}

    public function toDomain(PvpBattle $battle): PvpBattleState
    {
        $battle->loadMissing(['combatants.moves']);

        $hostRow = $battle->combatants->firstWhere('side', CombatSide::Player->value)
            ?? throw new \RuntimeException('PvP battle missing host combatant.');
        $guestRow = $battle->combatants->firstWhere('side', CombatSide::Opponent->value)
            ?? throw new \RuntimeException('PvP battle missing guest combatant.');

        $pending = is_array($battle->pending_attack_snapshot) && $battle->pending_attack_snapshot !== []
            ? PvpAttackCommitment::fromArray($battle->pending_attack_snapshot)
            : null;

        return new PvpBattleState(
            id: (string) $battle->id,
            roomId: (string) $battle->room_id,
            phase: PvpBattlePhase::from((string) $battle->phase),
            turnNumber: (int) $battle->turn_number,
            version: (int) $battle->version,
            rules: $this->rulesProvider->rulesFromSnapshot((array) ($battle->rules_snapshot ?? [])),
            hostUserId: (int) $battle->host_user_id,
            guestUserId: (int) $battle->guest_user_id,
            host: $this->combatantToSnapshot($hostRow, CombatSide::Player),
            guest: $this->combatantToSnapshot($guestRow, CombatSide::Opponent),
            currentAttackerUserId: (int) $battle->current_attacker_user_id,
            currentDefenderUserId: (int) $battle->current_defender_user_id,
            pendingAttack: $pending,
            lastEventSequence: (int) $battle->last_event_sequence,
            winnerUserId: $battle->winner_user_id !== null ? (int) $battle->winner_user_id : null,
            loserUserId: $battle->loser_user_id !== null ? (int) $battle->loser_user_id : null,
        );
    }

    public function applyTransition(PvpBattle $battle, PvpTurnTransition $transition): void
    {
        $state = $transition->nextState;
        $battle->loadMissing(['combatants.moves']);

        $this->syncCombatant($battle, $state->host);
        $this->syncCombatant($battle, $state->guest);

        $battle->phase = $state->phase->value;
        $battle->turn_number = $state->turnNumber;
        $battle->version = $state->version;
        $battle->rng_cursor = $transition->rngCursor;
        $battle->pending_attack_snapshot = $state->pendingAttack?->toArray();
        $battle->current_attacker_user_id = $state->currentAttackerUserId;
        $battle->current_defender_user_id = $state->currentDefenderUserId;
        $battle->last_event_sequence = $state->lastEventSequence;
        $battle->winner_user_id = $state->winnerUserId;
        $battle->loser_user_id = $state->loserUserId;
        $battle->status = $state->isFinished() ? PvpBattle::STATUS_FINISHED : PvpBattle::STATUS_ACTIVE;

        if ($state->isFinished()) {
            $battle->end_reason = PvpBattle::END_REASON_COMBAT;
            $battle->finished_at ??= Carbon::now();
        }

        $battle->save();

        foreach ($transition->events as $event) {
            $this->persistEvent($battle, $event);
        }
    }

    private function persistEvent(PvpBattle $battle, BattleEvent $event): void
    {
        PvpBattleEvent::query()->create([
            'battle_id' => $battle->id,
            'sequence' => $event->sequence,
            'type' => $event->type->value,
            'actor' => $event->actor?->value,
            'target' => $event->target?->value,
            'payload' => $event->payload,
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function syncCombatant(PvpBattle $battle, CombatantSnapshot $snapshot): void
    {
        $row = $battle->combatants->firstWhere('side', $snapshot->side->value)
            ?? throw new \RuntimeException('Missing combatant row for side '.$snapshot->side->value);

        $row->hp_current = $snapshot->hpCurrent;
        $row->stamina_current = $snapshot->staminaCurrent;
        $row->save();

        foreach ($snapshot->moves as $slot => $move) {
            /** @var PvpBattleCombatantMove|null $moveRow */
            $moveRow = $row->moves->firstWhere('slot', $slot);
            if ($moveRow === null) {
                continue;
            }
            $moveRow->pp_current = $move->ppCurrent;
            $moveRow->move_snapshot = $move->toArray();
            $moveRow->save();
        }
    }

    private function combatantToSnapshot(PvpBattleCombatant $row, CombatSide $side): CombatantSnapshot
    {
        $row->loadMissing('moves');
        $moves = [];
        foreach ($row->moves->sortBy('slot') as $moveRow) {
            $moves[] = MoveSnapshot::fromArray((array) $moveRow->move_snapshot);
        }

        return new CombatantSnapshot(
            side: $side,
            name: (string) $row->name,
            slug: (string) $row->slug,
            level: (int) $row->level,
            types: array_values((array) $row->types_snapshot),
            hpCurrent: (int) $row->hp_current,
            hpMax: (int) $row->hp_max,
            staminaCurrent: (int) $row->stamina_current,
            staminaMax: (int) $row->stamina_max,
            stats: (array) $row->stats_snapshot,
            moves: $moves,
        );
    }
}
