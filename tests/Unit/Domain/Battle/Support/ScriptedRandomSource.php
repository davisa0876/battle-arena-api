<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle\Support;

use App\Domain\Battle\Random\RandomSource;
use LogicException;

/**
 * Test double returning pre-scripted draws in order (floats for nextFloat, ints for nextInt).
 */
final class ScriptedRandomSource implements RandomSource
{
    private int $cursor = 0;

    /**
     * @param  list<int|float>  $values
     */
    public function __construct(private array $values = []) {}

    public function nextFloat(): float
    {
        return (float) $this->take();
    }

    public function nextInt(int $min, int $max): int
    {
        return (int) $this->take();
    }

    public function cursor(): int
    {
        return $this->cursor;
    }

    private function take(): int|float
    {
        if ($this->values === []) {
            throw new LogicException('ScriptedRandomSource ran out of values (unexpected RNG draw).');
        }
        $this->cursor++;

        return array_shift($this->values);
    }
}
