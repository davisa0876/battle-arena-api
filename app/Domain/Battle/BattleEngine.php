<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use App\Domain\Battle\Random\RandomSource;
use DomainException;

/**
 * Pure, stateless PvE battle transition engine: (state + action + randomness) => (state + events).
 *
 * Combat math is delegated to {@see CombatExchangeCalculator}. Phase transitions remain PvE-specific.
 *
 * Flow (spec 2.4 / 4.4):
 *  1. resolveAttackExchange(Player, ...)  -> opponent survives => awaiting_player_defense WITHOUT intent;
 *     the application layer lets the AI pick its counterattack and attaches it with
 *     BattleState::withPendingOpponentAttack().
 *  2. resolveCommittedAttack(...)          -> resolves the already-committed opponent attack against the
 *     player's defense; survivors => awaiting_player_attack (round + 1).
 *
 * RNG draw order inside one exchange (each draw only when applicable):
 *   accuracy (nextInt 1..100, skipped when accuracy >= 100)
 *   -> dodge (nextFloat, only for a connecting hit vs. `dodge`)
 *   -> damage RANDOM factor (nextFloat, skipped when min == max, only for a hit that is not dodged).
 *
 * Invalid commands throw DomainException before any state is produced (no resources are spent).
 */
final class BattleEngine
{
    public function __construct(
        private readonly CombatExchangeCalculator $exchange = new CombatExchangeCalculator,
    ) {}

    /**
     * Player attacks; the opponent AI's already-chosen defense is resolved in the same exchange.
     *
     * @param  int|null  $moveSlot  index into the player's moves, or null for basic_strike
     */
    public function resolvePlayerAttack(
        BattleState $state,
        ?int $moveSlot,
        DefenseType $opponentDefense,
        RandomSource $random,
        TypeEffectivenessChart $chart,
    ): BattleTransition {
        return $this->resolveAttackExchange($state, CombatSide::Player, $moveSlot, $opponentDefense, $random, $chart);
    }

    /**
     * Player recovers stamina and skips the attack turn when moves still have PP but none are affordable.
     * Advances to awaiting_player_defense so the application layer can attach the AI counterattack.
     */
    public function resolvePlayerRecoverSkipAttack(BattleState $state, int $rngCursor): BattleTransition
    {
        $this->assertPhase($state, BattlePhase::AwaitingPlayerAttack);

        $player = $state->player;
        if (! $player->canRecoverSkipAttack()) {
            throw new DomainException('Recover skip is only available when moves have PP but insufficient stamina.');
        }

        $events = [];
        $sequence = $state->lastEventSequence;
        $emit = function (BattleEventType $type, ?CombatSide $actor, ?CombatSide $target, array $payload = []) use (&$events, &$sequence): void {
            $events[] = new BattleEvent(++$sequence, $type, $actor, $target, $payload);
        };

        $emit(BattleEventType::AttackSkipped, CombatSide::Player, null, [
            'reason' => 'recover_stamina',
            'round' => $state->round,
        ]);

        $before = $player->staminaCurrent;
        $after = ResourceCalculator::applyRecover($before, $state->rules->recoverStamina, $player->staminaMax);
        $player = $player->withStamina($after);

        $emit(BattleEventType::StaminaRecovered, CombatSide::Player, CombatSide::Player, [
            'amount' => $after - $before,
            'before' => $before,
            'after' => $after,
            'reason' => 'attack_recover',
        ]);
        if ($before !== $after) {
            $emit(BattleEventType::StaminaChanged, CombatSide::Player, CombatSide::Player, [
                'reason' => 'attack_recover',
                'before' => $before,
                'after' => $after,
            ]);
        }

        $nextState = $state->with(
            phase: BattlePhase::AwaitingPlayerDefense,
            round: $state->round,
            version: $state->version + 1,
            player: $player,
            opponent: $state->opponent,
            lastEventSequence: $sequence,
            outcome: null,
        );

        return new BattleTransition($nextState, $events, $rngCursor);
    }

    /**
     * Resolves the opponent's previously committed counterattack against the player's defense.
     */
    public function resolveCommittedAttack(
        BattleState $state,
        DefenseType $playerDefense,
        RandomSource $random,
        TypeEffectivenessChart $chart,
    ): BattleTransition {
        $this->assertPhase($state, BattlePhase::AwaitingPlayerDefense);

        $intent = $state->pendingOpponentAttack
            ?? throw new DomainException('There is no committed opponent attack to defend against.');

        return $this->exchange($state, CombatSide::Opponent, $intent->moveSlot, $intent->move, $playerDefense, $random, $chart);
    }

    /**
     * Resolves one attack/defense exchange.
     *
     * - Player attacker: valid in awaiting_player_attack; $moveSlot null => basic_strike.
     * - Opponent attacker: valid only in awaiting_player_defense and must match the committed intent.
     *
     * @param  int|null  $moveSlot  null => basic_strike
     */
    public function resolveAttackExchange(
        BattleState $state,
        CombatSide $attackerSide,
        ?int $moveSlot,
        DefenseType $defense,
        RandomSource $random,
        TypeEffectivenessChart $chart,
    ): BattleTransition {
        if ($attackerSide === CombatSide::Opponent) {
            $this->assertPhase($state, BattlePhase::AwaitingPlayerDefense);
            $intent = $state->pendingOpponentAttack
                ?? throw new DomainException('The opponent has not committed an attack.');
            if ($intent->moveSlot !== $moveSlot) {
                throw new DomainException('The opponent cannot change its committed attack.');
            }

            return $this->exchange($state, CombatSide::Opponent, $intent->moveSlot, $intent->move, $defense, $random, $chart);
        }

        $this->assertPhase($state, BattlePhase::AwaitingPlayerAttack);
        $attacker = $state->player;
        $move = $moveSlot === null ? $state->rules->basicStrikeMove() : $attacker->move($moveSlot);

        return $this->exchange($state, CombatSide::Player, $moveSlot, $move, $defense, $random, $chart);
    }

    private function exchange(
        BattleState $state,
        CombatSide $attackerSide,
        ?int $moveSlot,
        MoveSnapshot $move,
        DefenseType $defenseType,
        RandomSource $random,
        TypeEffectivenessChart $chart,
    ): BattleTransition {
        $attacker = $state->combatant($attackerSide);
        $defender = $state->combatant($attackerSide->other());

        $result = $this->exchange->resolve(
            attacker: $attacker,
            defender: $defender,
            attackerSide: $attackerSide,
            moveSlot: $moveSlot,
            move: $move,
            defenseType: $defenseType,
            rules: $state->rules,
            random: $random,
            chart: $chart,
            eventSequence: $state->lastEventSequence,
            round: $state->round,
        );

        $events = $result->events;
        $sequence = $result->lastEventSequence;
        $attacker = $result->attacker;
        $defender = $result->defender;

        $phase = BattlePhase::AwaitingPlayerAttack;
        $round = $state->round;
        $outcome = null;

        if ($result->defenderFainted) {
            $outcome = $attackerSide === CombatSide::Player
                ? BattleRewardPolicy::OUTCOME_WIN
                : BattleRewardPolicy::OUTCOME_LOSS;
            $phase = BattlePhase::Finished;

            $events[] = new BattleEvent(++$sequence, BattleEventType::BattleFinished, null, null, [
                'outcome' => $outcome,
                'winner' => $attackerSide->value,
                'round' => $state->round,
            ]);
        } elseif ($attackerSide === CombatSide::Player) {
            $phase = BattlePhase::AwaitingPlayerDefense;
        } else {
            $round = $state->round + 1;
        }

        $nextState = $state->with(
            phase: $phase,
            round: $round,
            version: $state->version + 1,
            player: $attackerSide === CombatSide::Player ? $attacker : $defender,
            opponent: $attackerSide === CombatSide::Opponent ? $attacker : $defender,
            lastEventSequence: $sequence,
            outcome: $outcome,
        );

        return new BattleTransition($nextState, $events, $result->rngCursor);
    }

    private function assertPhase(BattleState $state, BattlePhase $expected): void
    {
        if ($state->phase !== $expected) {
            throw new DomainException(sprintf(
                'Invalid battle phase: expected %s, got %s.',
                $expected->value,
                $state->phase->value,
            ));
        }
    }
}
