<?php

declare(strict_types=1);

namespace App\Domain\Battle\Random;

/**
 * Deterministic source of randomness used by the battle engine.
 *
 * Every draw (nextFloat or nextInt) consumes exactly one position of the
 * sequence, so the cursor can be persisted and used to resume a match.
 */
interface RandomSource
{
    /**
     * @return float value in the half-open interval [0, 1)
     */
    public function nextFloat(): float;

    /**
     * @return int value in the closed interval [$min, $max]
     */
    public function nextInt(int $min, int $max): int;

    /**
     * Number of draws consumed since the sequence origin.
     */
    public function cursor(): int;
}
