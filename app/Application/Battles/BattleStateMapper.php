<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Domain\Battle\BattleEvent;
use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\BattleState;
use App\Domain\Battle\BattleTransition;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\Models\BattleCombatant;
use App\Domain\Battle\Models\BattleCombatantMove;
use App\Domain\Battle\Models\BattleEventRecord;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\OpponentIntent;
use Illuminate\Support\Carbon;

/**
 * Maps Eloquent battle rows <-> pure domain BattleState and persists transitions.
 */
final class BattleStateMapper
{
    public function __construct(
        private readonly PveRulesProvider $rulesProvider,
    ) {}

    public function toDomain(PveBattle $battle): BattleState
    {
        $battle->loadMissing(['combatants.moves']);

        $playerRow = $battle->combatants->firstWhere('side', CombatSide::Player->value)
            ?? throw new \RuntimeException('Battle is missing the player combatant.');
        $opponentRow = $battle->combatants->firstWhere('side', CombatSide::Opponent->value)
            ?? throw new \RuntimeException('Battle is missing the opponent combatant.');

        $rules = $this->rulesProvider->rulesFromSnapshot((array) ($battle->rules_snapshot ?? []));
        $pending = is_array($battle->opponent_pending_action) && $battle->opponent_pending_action !== []
            ? OpponentIntent::fromArray($battle->opponent_pending_action)
            : null;

        return new BattleState(
            id: (string) $battle->id,
            phase: BattlePhase::from((string) $battle->phase),
            round: (int) $battle->round,
            version: (int) $battle->version,
            rules: $rules,
            player: $this->combatantToSnapshot($playerRow, CombatSide::Player),
            opponent: $this->combatantToSnapshot($opponentRow, CombatSide::Opponent),
            pendingOpponentAttack: $pending,
            lastEventSequence: (int) ($battle->events()->max('sequence') ?? 0),
            outcome: $battle->outcome,
        );
    }

    public function applyTransition(PveBattle $battle, BattleTransition $transition): void
    {
        $state = $transition->nextState;
        $battle->loadMissing(['combatants.moves']);

        $this->syncCombatant($battle, $state->player);
        $this->syncCombatant($battle, $state->opponent);

        $battle->phase = $state->phase->value;
        $battle->round = $state->round;
        $battle->version = $state->version;
        $battle->rng_cursor = $transition->rngCursor;
        $battle->opponent_pending_action = $state->pendingOpponentAttack?->toArray();
        $battle->outcome = $state->outcome;
        $battle->status = $state->isFinished() ? PveBattle::STATUS_FINISHED : PveBattle::STATUS_ACTIVE;
        if ($state->isFinished()) {
            if ($battle->end_reason === null) {
                $battle->end_reason = PveBattle::END_REASON_COMBAT;
            }
            if ($battle->finished_at === null) {
                $battle->finished_at = Carbon::now();
            }
        }
        $battle->save();

        foreach ($transition->events as $event) {
            $this->persistEvent($battle, $event);
        }
    }

    /**
     * @param  list<BattleEvent>  $events
     */
    public function persistEvents(PveBattle $battle, array $events): void
    {
        foreach ($events as $event) {
            $this->persistEvent($battle, $event);
        }
    }

    private function persistEvent(PveBattle $battle, BattleEvent $event): void
    {
        BattleEventRecord::query()->create([
            'battle_id' => $battle->id,
            'sequence' => $event->sequence,
            'type' => $event->type->value,
            'actor' => $event->actor?->value,
            'target' => $event->target?->value,
            'payload' => $event->payload,
            'occurred_at' => Carbon::now(),
        ]);
    }

    private function syncCombatant(PveBattle $battle, CombatantSnapshot $snapshot): void
    {
        /** @var BattleCombatant $row */
        $row = $battle->combatants->firstWhere('side', $snapshot->side->value)
            ?? throw new \RuntimeException("Missing combatant side {$snapshot->side->value}");

        $row->hp_current = $snapshot->hpCurrent;
        $row->stamina_current = $snapshot->staminaCurrent;
        $row->save();

        foreach ($snapshot->moves as $slot => $move) {
            /** @var BattleCombatantMove|null $moveRow */
            $moveRow = $row->moves->firstWhere('slot', $slot);
            if ($moveRow === null) {
                continue;
            }
            $moveRow->pp_current = $move->ppCurrent;
            $moveRow->move_snapshot = $move->toArray();
            $moveRow->save();
        }
    }

    private function combatantToSnapshot(BattleCombatant $row, CombatSide $side): CombatantSnapshot
    {
        $moves = $row->moves->sortBy('slot')->values()->map(
            static function (BattleCombatantMove $move): MoveSnapshot {
                $snap = (array) $move->move_snapshot;
                $snap['pp_current'] = $move->pp_current;
                $snap['pp_max'] = $move->pp_max;
                $snap['stamina_cost'] = $move->stamina_cost;
                if (! isset($snap['id']) && $move->move_id) {
                    $snap['id'] = $move->move_id;
                }

                return MoveSnapshot::fromArray($snap);
            },
        )->all();

        /** @var array{attack: int, defense: int, special_attack: int, special_defense: int, speed: int} $stats */
        $stats = (array) $row->stats_snapshot;

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
            stats: $stats,
            moves: $moves,
        );
    }
}
