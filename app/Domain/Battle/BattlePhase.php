<?php

declare(strict_types=1);

namespace App\Domain\Battle;

enum BattlePhase: string
{
    case AwaitingPlayerAttack = 'awaiting_player_attack';
    case AwaitingPlayerDefense = 'awaiting_player_defense';
    case Finished = 'finished';
}
