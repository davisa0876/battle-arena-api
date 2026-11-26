<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Pure result of one attack/defense exchange (mode-agnostic).
 */
final readonly class CombatExchangeResult
{
    /**
     * @param  list<BattleEvent>  $events
     */
    public function __construct(
        public CombatantSnapshot $attacker,
        public CombatantSnapshot $defender,
        public array $events,
        public int $lastEventSequence,
        public int $rngCursor,
        public bool $defenderFainted,
    ) {}
}
