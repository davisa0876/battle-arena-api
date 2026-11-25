<?php

declare(strict_types=1);

namespace App\Domain\Battle;

enum BattleEventType: string
{
    case BattleStarted = 'battle_started';
    case AttackStarted = 'attack_started';
    case AttackSkipped = 'attack_skipped';
    case ResourcesSpent = 'resources_spent';
    case DefenseSelected = 'defense_selected';
    case AttackMissed = 'attack_missed';
    case DefenseDodged = 'defense_dodged';
    case DamageBlocked = 'damage_blocked';
    case DamageApplied = 'damage_applied';
    case StaminaRecovered = 'stamina_recovered';
    case CombatantFainted = 'combatant_fainted';
    case BattleFinished = 'battle_finished';
    case HpChanged = 'hp_changed';
    case PpChanged = 'pp_changed';
    case StaminaChanged = 'stamina_changed';
}
