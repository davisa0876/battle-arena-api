<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use App\Domain\Battle\Random\RandomSource;
use DomainException;

/**
 * Pure, stateless battle transition engine: (state + action + randomness) => (state + events).
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
        $rules = $state->rules;
        $defenderSide = $attackerSide->other();
        $attacker = $state->combatant($attackerSide);
        $defender = $state->combatant($defenderSide);

        $this->assertCanAttack($attacker, $moveSlot, $move);

        $defenseCost = $rules->defenseStaminaCost($defenseType);
        if (! ResourceCalculator::canAfford($defender->staminaCurrent, $defenseCost)) {
            throw new DomainException("Not enough stamina for {$defenseType->value}.");
        }

        $events = [];
        $sequence = $state->lastEventSequence;
        $emit = function (BattleEventType $type, ?CombatSide $actor, ?CombatSide $target, array $payload = []) use (&$events, &$sequence): void {
            $events[] = new BattleEvent(++$sequence, $type, $actor, $target, $payload);
        };

        // 1. Attack declared.
        $emit(BattleEventType::AttackStarted, $attackerSide, $defenderSide, [
            'move_slot' => $moveSlot,
            'move_slug' => $move->slug,
            'move_name' => $move->name,
            'type_slug' => $move->typeSlug,
            'damage_class' => $move->damageClass,
            'is_basic_strike' => $move->isBasicStrike,
            'round' => $state->round,
        ]);

        // 2. Attacker pays PP (not for basic_strike) and stamina, even if the attack later fails.
        $staminaBefore = $attacker->staminaCurrent;
        $staminaAfter = ResourceCalculator::applySpend($staminaBefore, $move->staminaCost, $attacker->staminaMax);
        $attacker = $attacker->withStamina($staminaAfter);

        $ppBefore = null;
        $ppAfter = null;
        if (! $move->isBasicStrike) {
            $ppBefore = $attacker->move((int) $moveSlot)->ppCurrent;
            $ppAfter = ResourceCalculator::applySpend($ppBefore, 1, $attacker->move((int) $moveSlot)->ppMax);
            $attacker = $attacker->withMovePp((int) $moveSlot, $ppAfter);
        }

        $emit(BattleEventType::ResourcesSpent, $attackerSide, $attackerSide, [
            'move_slug' => $move->slug,
            'pp_spent' => $move->isBasicStrike ? 0 : 1,
            'pp_before' => $ppBefore,
            'pp_after' => $ppAfter,
            'stamina_spent' => $staminaBefore - $staminaAfter,
            'stamina_before' => $staminaBefore,
            'stamina_after' => $staminaAfter,
        ]);
        if (! $move->isBasicStrike) {
            $emit(BattleEventType::PpChanged, $attackerSide, $attackerSide, [
                'move_slug' => $move->slug,
                'before' => $ppBefore,
                'after' => $ppAfter,
            ]);
        }
        if ($staminaBefore !== $staminaAfter) {
            $emit(BattleEventType::StaminaChanged, $attackerSide, $attackerSide, [
                'reason' => 'attack_cost',
                'before' => $staminaBefore,
                'after' => $staminaAfter,
            ]);
        }

        // 3. Defender reaction: cost / credit applied exactly once.
        $emit(BattleEventType::DefenseSelected, $defenderSide, $attackerSide, [
            'defense' => $defenseType->value,
            'stamina_cost' => $defenseCost,
        ]);

        $defStaminaBefore = $defender->staminaCurrent;
        $defStamina = ResourceCalculator::applySpend($defStaminaBefore, $defenseCost, $defender->staminaMax);
        if ($defenseCost > 0) {
            $emit(BattleEventType::StaminaChanged, $defenderSide, $defenderSide, [
                'reason' => 'defense_cost',
                'before' => $defStaminaBefore,
                'after' => $defStamina,
            ]);
        }
        if ($defenseType === DefenseType::Recover) {
            $recovered = ResourceCalculator::applyRecover($defStamina, $rules->recoverStamina, $defender->staminaMax);
            $emit(BattleEventType::StaminaRecovered, $defenderSide, $defenderSide, [
                'amount' => $recovered - $defStamina,
                'before' => $defStamina,
                'after' => $recovered,
            ]);
            if ($recovered !== $defStamina) {
                $emit(BattleEventType::StaminaChanged, $defenderSide, $defenderSide, [
                    'reason' => 'recover',
                    'before' => $defStamina,
                    'after' => $recovered,
                ]);
            }
            $defStamina = $recovered;
        }
        $defender = $defender->withStamina($defStamina);

        // 4. Accuracy, then dodge, then damage.
        $connected = true;
        if ($move->accuracy < 100) {
            $roll = $random->nextInt(1, 100);
            if ($roll > $move->accuracy) {
                $connected = false;
                $emit(BattleEventType::AttackMissed, $attackerSide, $defenderSide, [
                    'move_slug' => $move->slug,
                    'accuracy' => $move->accuracy,
                    'damage' => 0,
                ]);
            }
        }

        if ($connected && $defenseType === DefenseType::Dodge) {
            if ($random->nextFloat() < $rules->dodgeChance) {
                $connected = false;
                $emit(BattleEventType::DefenseDodged, $defenderSide, $attackerSide, [
                    'move_slug' => $move->slug,
                    'dodge_chance' => $rules->dodgeChance,
                    'damage' => 0,
                ]);
            }
        }

        if ($connected) {
            $typeMultiplier = $chart->multiplier($move->typeSlug, $defender->types);
            $randomFactor = DamageCalculator::rollRandomFactor($rules, $random);
            $result = DamageCalculator::calculate(
                $attacker,
                $defender,
                $move,
                $typeMultiplier,
                $randomFactor,
                $rules->defenseDamageMultiplier($defenseType),
                $rules,
            );

            $hpBefore = $defender->hpCurrent;
            $defender = $defender->withHp($hpBefore - $result->damage);
            $hpAfter = $defender->hpCurrent;

            if ($defenseType === DefenseType::Block && ! $result->isImmune) {
                $emit(BattleEventType::DamageBlocked, $defenderSide, $attackerSide, [
                    'move_slug' => $move->slug,
                    'damage_before_block' => $result->modifiedDamage,
                    'damage_after_block' => $result->damage,
                    'defense_multiplier' => $result->defenseMultiplier,
                ]);
            }

            $emit(BattleEventType::DamageApplied, $attackerSide, $defenderSide, array_merge([
                'move_slug' => $move->slug,
                'defense' => $defenseType->value,
                'hp_before' => $hpBefore,
                'hp_after' => $hpAfter,
            ], $result->toPayload()));

            if ($hpBefore !== $hpAfter) {
                $emit(BattleEventType::HpChanged, $defenderSide, $defenderSide, [
                    'before' => $hpBefore,
                    'after' => $hpAfter,
                    'max' => $defender->hpMax,
                ]);
            }
        }

        // 5. Knockout and next phase.
        $phase = BattlePhase::AwaitingPlayerAttack;
        $round = $state->round;
        $outcome = null;

        if ($defender->isFainted()) {
            $outcome = $defenderSide === CombatSide::Opponent
                ? BattleRewardPolicy::OUTCOME_WIN
                : BattleRewardPolicy::OUTCOME_LOSS;
            $phase = BattlePhase::Finished;

            $emit(BattleEventType::CombatantFainted, $defenderSide, $attackerSide, [
                'name' => $defender->name,
                'slug' => $defender->slug,
            ]);
            $emit(BattleEventType::BattleFinished, null, null, [
                'outcome' => $outcome,
                'winner' => $attackerSide->value,
                'round' => $state->round,
            ]);
        } elseif ($attackerSide === CombatSide::Player) {
            $phase = BattlePhase::AwaitingPlayerDefense; // caller attaches the AI's committed attack
        } else {
            $round = $state->round + 1; // both survived: new round
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

        return new BattleTransition($nextState, $events, $random->cursor());
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

    private function assertCanAttack(CombatantSnapshot $attacker, ?int $moveSlot, MoveSnapshot $move): void
    {
        if ($moveSlot === null) {
            if (! $move->isBasicStrike) {
                throw new DomainException('Only basic_strike can be used without a move slot.');
            }
            // AI and deadlock fallback: basic_strike whenever no equipped move is currently usable.
            // Players normally recover+skip instead when PP remains but stamina is too low.
            if ($attacker->hasUsableMove()) {
                throw new DomainException('basic_strike is only available when no move can be used.');
            }
            if (! ResourceCalculator::canAfford($attacker->staminaCurrent, $move->staminaCost)) {
                throw new DomainException('Not enough stamina for basic_strike.');
            }

            return;
        }

        $slotMove = $attacker->move($moveSlot);
        if ($slotMove->ppCurrent <= 0) {
            throw new DomainException("Move {$slotMove->slug} has no PP left.");
        }
        if (! ResourceCalculator::canAfford($attacker->staminaCurrent, $slotMove->staminaCost)) {
            throw new DomainException("Not enough stamina for {$slotMove->slug}.");
        }
    }
}
