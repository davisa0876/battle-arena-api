<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Result of one engine step: the new state, ordered events, and the RNG cursor to persist.
 */
final readonly class BattleTransition
{
    /**
     * @param  list<BattleEvent>  $events
     */
    public function __construct(
        public BattleState $nextState,
        public array $events,
        public int $rngCursor,
    ) {}
}
