<?php

namespace App\Domain\Progression;

use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\Models\PlayerXpEvent;

final readonly class XpAwardResult
{
    public function __construct(
        public PlayerXpEvent $event,
        public PlayerProgress $progress,
        public int $levelsGained,
        public bool $wasDuplicate,
    ) {}
}
