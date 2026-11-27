<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use App\Domain\Battle\Random\RandomSource;
use DomainException;

/**
 * Mode-agnostic pure attack/defense exchange.
 * Spends PP/stamina and applies damage exactly once at resolution.
 * Does not know about PvE/PvP turn phases or user IDs.
 */
final class CombatExchangeCalculator
{
    /**
     * @param  int|null  $moveSlot  null => basic_strike
     */
    public function resolve(
        CombatantSnapshot $attacker,
        CombatantSnapshot $defender,
        CombatSide $attackerSide,
        ?int $moveSlot,
        MoveSnapshot $move,
        DefenseType $defenseType,
        BattleRules $rules,
        RandomSource $random,
        TypeEffectivenessChart $chart,
        int $eventSequence,
        int $round,
        bool $emitAttackStarted = true,
    ): CombatExchangeResult {
        $defenderSide = $attackerSide->other();

        $this->assertCanAttack($attacker, $moveSlot, $move);

        $defenseCost = $rules->defenseStaminaCost($defenseType);
        if (! ResourceCalculator::canAfford($defender->staminaCurrent, $defenseCost)) {
            throw new DomainException("Not enough stamina for {$defenseType->value}.");
        }

        $events = [];
        $sequence = $eventSequence;
        $emit = function (BattleEventType $type, ?CombatSide $actor, ?CombatSide $target, array $payload = []) use (&$events, &$sequence): void {
            $events[] = new BattleEvent(++$sequence, $type, $actor, $target, $payload);
        };

        if ($emitAttackStarted) {
            $emit(BattleEventType::AttackStarted, $attackerSide, $defenderSide, [
                'move_slot' => $moveSlot,
                'move_slug' => $move->slug,
                'move_name' => $move->name,
                'type_slug' => $move->typeSlug,
                'damage_class' => $move->damageClass,
                'is_basic_strike' => $move->isBasicStrike,
                'round' => $round,
            ]);
        }

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
                    'reason' => 'damage',
                ]);
            }
        }

        // Heal patches HP after the hit lands; fainted combatants cannot self-revive.
        if ($defenseType === DefenseType::Heal && ! $defender->isFainted() && $rules->recoverHp > 0) {
            $healBefore = $defender->hpCurrent;
            $healAfter = ResourceCalculator::applyRecover($healBefore, $rules->recoverHp, $defender->hpMax);
            if ($healAfter !== $healBefore) {
                $defender = $defender->withHp($healAfter);
                $emit(BattleEventType::HpChanged, $defenderSide, $defenderSide, [
                    'before' => $healBefore,
                    'after' => $healAfter,
                    'max' => $defender->hpMax,
                    'reason' => 'heal',
                    'amount' => $healAfter - $healBefore,
                ]);
            }
        }

        $defenderFainted = $defender->isFainted();
        if ($defenderFainted) {
            $emit(BattleEventType::CombatantFainted, $defenderSide, $attackerSide, [
                'name' => $defender->name,
                'slug' => $defender->slug,
            ]);
        }

        return new CombatExchangeResult(
            attacker: $attacker,
            defender: $defender,
            events: $events,
            lastEventSequence: $sequence,
            rngCursor: $random->cursor(),
            defenderFainted: $defenderFainted,
        );
    }

    /**
     * @param  int|null  $moveSlot
     */
    public function assertCanAttack(CombatantSnapshot $attacker, ?int $moveSlot, MoveSnapshot $move): void
    {
        if ($moveSlot === null) {
            if (! $move->isBasicStrike) {
                throw new DomainException('Only basic_strike can be used without a move slot.');
            }
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
