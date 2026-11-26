<?php

declare(strict_types=1);

namespace App\Domain\Battle\Pvp;

use App\Domain\Battle\BattleEvent;

/**
 * @param  list<BattleEvent>  $events
 */
final readonly class PvpTurnTransition
{
    /**
     * @param  list<BattleEvent>  $events
     */
    public function __construct(
        public PvpBattleState $nextState,
        public array $events,
        public int $rngCursor,
    ) {}
}
