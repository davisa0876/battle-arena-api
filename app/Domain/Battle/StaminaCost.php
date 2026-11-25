<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Stamina cost helper: clamp(base + perStep * ceil(power / step), min, max).
 *
 * Defaults match spec 4.2: clamp(3 + 3 * ceil(power / 10), 10, 35).
 */
final class StaminaCost
{
    public const DEFAULT_BASE = 3;

    public const DEFAULT_PER_STEP = 3;

    public const DEFAULT_POWER_STEP = 10;

    public const DEFAULT_MIN = 10;

    public const DEFAULT_MAX = 35;

    public static function forPower(
        int $power,
        int $base = self::DEFAULT_BASE,
        int $perStep = self::DEFAULT_PER_STEP,
        int $powerStep = self::DEFAULT_POWER_STEP,
        int $min = self::DEFAULT_MIN,
        int $max = self::DEFAULT_MAX,
    ): int {
        $steps = (int) ceil(max(0, $power) / max(1, $powerStep));

        return max($min, min($max, $base + $perStep * $steps));
    }
}
