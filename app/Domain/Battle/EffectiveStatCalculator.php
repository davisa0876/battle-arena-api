<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Simplified level-based effective stats (no IVs, EVs or nature). Formula version: pve-0.1.
 */
final class EffectiveStatCalculator
{
    /**
     * hp = floor((2 * base * level) / 100) + level + 10
     */
    public static function hp(int $baseStat, int $level): int
    {
        return intdiv(2 * $baseStat * $level, 100) + $level + 10;
    }

    /**
     * other = floor((2 * base * level) / 100) + 5
     */
    public static function stat(int $baseStat, int $level): int
    {
        return intdiv(2 * $baseStat * $level, 100) + 5;
    }
}
