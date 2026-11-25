<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Pure integer helpers for HP, stamina and PP bounds.
 */
final class ResourceCalculator
{
    public static function staminaCostForPower(int $power, BattleRules $rules): int
    {
        $f = $rules->staminaCostFormula;

        return StaminaCost::forPower($power, $f['base'], $f['per_step'], $f['power_step'], $f['min'], $f['max']);
    }

    public static function canAfford(int $current, int $cost): bool
    {
        return $cost <= $current;
    }

    /**
     * Subtract a cost; result is clamped to [0, max]. Callers must check canAfford() first.
     */
    public static function applySpend(int $current, int $cost, ?int $max = null): int
    {
        return self::clamp($current - $cost, 0, $max ?? PHP_INT_MAX);
    }

    /**
     * Add a recovery amount, capped at $max.
     */
    public static function applyRecover(int $current, int $amount, int $max): int
    {
        return self::clamp($current + $amount, 0, $max);
    }

    public static function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
