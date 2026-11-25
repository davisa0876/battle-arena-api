<?php

namespace App\Domain\Progression;

/**
 * Read-only view of a trainer's position on the XP curve.
 */
final readonly class TrainerProgressSnapshot
{
    public function __construct(
        public int $level,
        public int $totalXp,
        public int $xpIntoLevel,
        public ?int $xpToNextLevel,
        public ?int $xpForNextLevel,
        public float $progressPercent,
        public bool $isMaxLevel,
    ) {}
}
