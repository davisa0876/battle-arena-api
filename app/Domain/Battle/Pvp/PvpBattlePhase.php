<?php

declare(strict_types=1);

namespace App\Domain\Battle\Pvp;

enum PvpBattlePhase: string
{
    case AwaitingAttack = 'awaiting_attack';
    case AwaitingDefense = 'awaiting_defense';
    case Finished = 'finished';
}
