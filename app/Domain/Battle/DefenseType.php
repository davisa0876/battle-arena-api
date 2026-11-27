<?php

declare(strict_types=1);

namespace App\Domain\Battle;

enum DefenseType: string
{
    case Endure = 'endure';
    case Block = 'block';
    case Dodge = 'dodge';
    case Recover = 'recover';
    case Heal = 'heal';
}
