<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use App\Domain\Battle\Random\RandomSource;

/**
 * Pure damage formula (spec section 5):
 *
 *   baseDamage     = floor(((2*L/5 + 2) * P * A/D) / 50 + 2)
 *   modifiedDamage = floor(baseDamage * STAB * TYPE * RANDOM)
 *   finalDamage    = floor(modifiedDamage * DEFENSE_MULTIPLIER)
 *   finalDamage    = clamp(finalDamage, 0, defender.current_hp)
 *
 * A connected, non-immune hit never rounds to 0 (minimum 1 HP before clamping).
 * Intermediate floats are rounded to 6 decimals before flooring so results are
 * identical across runtimes (e.g. 60 * 0.9 never becomes 53.99999999).
 */
final class DamageCalculator
{
    private const EPSILON_DECIMALS = 6;

    /**
     * @param  float  $defenseMultiplier  multiplier of the chosen defense for a CONNECTED hit (block 0.5, others 1)
     */
    public static function calculate(
        CombatantSnapshot $attacker,
        CombatantSnapshot $defender,
        MoveSnapshot $move,
        float $typeMultiplier,
        float $randomFactor,
        float $defenseMultiplier,
        BattleRules $rules,
    ): DamageResult {
        $isImmune = $typeMultiplier <= 0.0;
        $stab = $attacker->hasType($move->typeSlug) ? $rules->stab : 1.0;

        $offense = $attacker->stat($move->isSpecial() ? 'special_attack' : 'attack');
        $defense = max(1, $defender->stat($move->isSpecial() ? 'special_defense' : 'defense'));

        $baseDamage = self::baseDamage($attacker->level, $move->power, $offense, $defense);

        $modified = self::floor($baseDamage * ($stab * $typeMultiplier * $randomFactor));
        $final = self::floor($modified * $defenseMultiplier);

        $minimumApplied = false;
        if ($move->power > 0 && ! $isImmune && $defenseMultiplier > 0.0 && $final === 0) {
            $final = 1;
            $minimumApplied = true;
        }
        if ($isImmune || $move->power <= 0) {
            $final = 0;
            $minimumApplied = false;
        }

        $clamped = ResourceCalculator::clamp($final, 0, $defender->hpCurrent);

        return new DamageResult(
            baseDamage: $baseDamage,
            stab: $stab,
            typeMultiplier: $typeMultiplier,
            randomFactor: $randomFactor,
            modifiedDamage: $modified,
            defenseMultiplier: $defenseMultiplier,
            unclampedDamage: $final,
            damage: $clamped,
            isImmune: $isImmune,
            minimumApplied: $minimumApplied,
        );
    }

    public static function baseDamage(int $level, int $power, int $offense, int $defense): int
    {
        if ($power <= 0) {
            return 0;
        }

        $value = (((2 * $level / 5) + 2) * $power * $offense / max(1, $defense)) / 50 + 2;

        return self::floor($value);
    }

    /**
     * RANDOM factor in [min, max]. Consumes exactly one draw, unless the range is degenerate (min == max).
     */
    public static function rollRandomFactor(BattleRules $rules, RandomSource $random): float
    {
        if ($rules->randomDamageMin === $rules->randomDamageMax) {
            return $rules->randomDamageMin;
        }

        return round(
            $rules->randomDamageMin + $random->nextFloat() * ($rules->randomDamageMax - $rules->randomDamageMin),
            4,
        );
    }

    private static function floor(float $value): int
    {
        return (int) floor(round($value, self::EPSILON_DECIMALS));
    }
}
