<?php

declare(strict_types=1);

namespace App\Domain\Battle\Random;

use InvalidArgumentException;

/**
 * Deterministic linear congruential generator (glibc-style constants, 31-bit state).
 *
 * Persist either (seed, cursor) or (state, cursor) to resume the exact sequence.
 * It is NOT cryptographically secure; seeds must stay server-side.
 */
final class SeededRandomSource implements RandomSource
{
    private const MODULUS = 2147483648; // 2^31

    private const MULTIPLIER = 1103515245;

    private const INCREMENT = 12345;

    private function __construct(
        private int $state,
        private int $cursor,
    ) {}

    /**
     * Start from a seed and fast-forward $cursor draws.
     */
    public static function fromSeed(int $seed, int $cursor = 0): self
    {
        if ($cursor < 0) {
            throw new InvalidArgumentException('Cursor must not be negative.');
        }

        $source = new self(self::normalize($seed), 0);

        for ($i = 0; $i < $cursor; $i++) {
            $source->advance();
        }

        return $source;
    }

    /**
     * Resume directly from a persisted internal state.
     */
    public static function fromState(int $state, int $cursor): self
    {
        if ($cursor < 0) {
            throw new InvalidArgumentException('Cursor must not be negative.');
        }

        return new self(self::normalize($state), $cursor);
    }

    public function nextFloat(): float
    {
        return $this->advance() / self::MODULUS;
    }

    public function nextInt(int $min, int $max): int
    {
        if ($min > $max) {
            throw new InvalidArgumentException('min must not be greater than max.');
        }

        $range = $max - $min + 1;

        return $min + (int) floor($this->nextFloat() * $range);
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    /**
     * Current internal LCG state (persist together with cursor).
     */
    public function state(): int
    {
        return $this->state;
    }

    private function advance(): int
    {
        $this->state = ($this->state * self::MULTIPLIER + self::INCREMENT) % self::MODULUS;
        $this->cursor++;

        return $this->state;
    }

    private static function normalize(int $value): int
    {
        return (($value % self::MODULUS) + self::MODULUS) % self::MODULUS;
    }
}
