<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle;

use App\Domain\Battle\Random\SeededRandomSource;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class SeededRandomSourceTest extends TestCase
{
    public function test_same_seed_produces_same_sequence(): void
    {
        $a = SeededRandomSource::fromSeed(42);
        $b = SeededRandomSource::fromSeed(42);

        for ($i = 0; $i < 50; $i++) {
            $this->assertSame($a->nextFloat(), $b->nextFloat());
        }
        $this->assertSame(50, $a->cursor());
    }

    public function test_different_seeds_diverge(): void
    {
        $this->assertNotSame(SeededRandomSource::fromSeed(1)->nextFloat(), SeededRandomSource::fromSeed(2)->nextFloat());
    }

    public function test_float_is_within_unit_interval_and_int_within_bounds(): void
    {
        $rng = SeededRandomSource::fromSeed(2026);

        for ($i = 0; $i < 500; $i++) {
            $f = $rng->nextFloat();
            $this->assertGreaterThanOrEqual(0.0, $f);
            $this->assertLessThan(1.0, $f);

            $n = $rng->nextInt(1, 100);
            $this->assertGreaterThanOrEqual(1, $n);
            $this->assertLessThanOrEqual(100, $n);
        }
    }

    public function test_cursor_and_state_allow_resuming(): void
    {
        $original = SeededRandomSource::fromSeed(99);
        for ($i = 0; $i < 7; $i++) {
            $original->nextInt(1, 100);
        }

        $fromSeed = SeededRandomSource::fromSeed(99, $original->cursor());
        $fromState = SeededRandomSource::fromState($original->state(), $original->cursor());

        $expected = $original->nextFloat();
        $this->assertSame($expected, $fromSeed->nextFloat());
        $this->assertSame($expected, $fromState->nextFloat());
        $this->assertSame(8, $fromState->cursor());
    }

    public function test_invalid_arguments_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SeededRandomSource::fromSeed(1)->nextInt(5, 1);
    }

    public function test_negative_seed_is_normalized(): void
    {
        $this->assertGreaterThanOrEqual(0.0, SeededRandomSource::fromSeed(-5)->nextFloat());
    }
}
