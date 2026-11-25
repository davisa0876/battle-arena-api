<?php

declare(strict_types=1);

namespace App\Domain\Battle;

enum CombatSide: string
{
    case Player = 'player';
    case Opponent = 'opponent';

    public function other(): self
    {
        return match ($this) {
            self::Player => self::Opponent,
            self::Opponent => self::Player,
        };
    }
}
