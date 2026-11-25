<?php

declare(strict_types=1);

namespace App\Infrastructure\Battles;

use App\Domain\Battle\BattleRules;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\OpponentIntent;
use App\Domain\Battle\Random\RandomSource;
use App\Domain\Battle\TypeEffectivenessChart;

/**
 * Rule-based PvE AI. Same PP/stamina restrictions as the player; never reads future player defenses.
 */
final class RuleBasedOpponent
{
    public function chooseDefense(
        CombatantSnapshot $self,
        CombatantSnapshot $foe,
        MoveSnapshot $incoming,
        BattleRules $rules,
        TypeEffectivenessChart $chart,
        RandomSource $random,
        string $difficulty,
    ): DefenseType {
        $affordable = $this->affordableDefenses($self, $rules);
        if ($affordable === []) {
            return DefenseType::Endure;
        }

        if ($difficulty === 'easy') {
            return $affordable[$random->nextInt(0, count($affordable) - 1)];
        }

        // normal: block strong incoming hits when affordable; recover when stamina is low; else endure/dodge mix.
        $typeMult = $chart->multiplier($incoming->typeSlug, $self->types);
        $looksDangerous = $incoming->power >= 60 || $typeMult >= 2.0;
        $staminaRatio = $self->staminaMax > 0 ? $self->staminaCurrent / $self->staminaMax : 0.0;

        if ($looksDangerous && in_array(DefenseType::Block, $affordable, true) && $random->nextFloat() < 0.7) {
            return DefenseType::Block;
        }

        if ($staminaRatio < 0.35 && in_array(DefenseType::Recover, $affordable, true) && $random->nextFloat() < 0.6) {
            return DefenseType::Recover;
        }

        if (in_array(DefenseType::Dodge, $affordable, true) && $looksDangerous && $random->nextFloat() < 0.35) {
            return DefenseType::Dodge;
        }

        if (in_array(DefenseType::Endure, $affordable, true)) {
            return DefenseType::Endure;
        }

        return $affordable[$random->nextInt(0, count($affordable) - 1)];
    }

    public function chooseAttack(
        CombatantSnapshot $self,
        CombatantSnapshot $foe,
        BattleRules $rules,
        TypeEffectivenessChart $chart,
        RandomSource $random,
        string $difficulty,
    ): OpponentIntent {
        $options = [];
        foreach ($self->moves as $slot => $move) {
            if ($self->canUseMove($slot)) {
                $options[] = ['slot' => $slot, 'move' => $move];
            }
        }

        if ($options === []) {
            return OpponentIntent::basicStrike($rules->basicStrikeMove());
        }

        if ($difficulty === 'easy') {
            $pick = $options[$random->nextInt(0, count($options) - 1)];

            return OpponentIntent::forMove($pick['slot'], $pick['move']);
        }

        // normal: weight by expected type effectiveness * power.
        $bestScore = -1.0;
        $best = [];
        foreach ($options as $option) {
            /** @var MoveSnapshot $move */
            $move = $option['move'];
            $score = $move->power * $chart->multiplier($move->typeSlug, $foe->types);
            if ($self->hasType($move->typeSlug)) {
                $score *= $rules->stab;
            }
            if ($score > $bestScore + 0.01) {
                $bestScore = $score;
                $best = [$option];
            } elseif (abs($score - $bestScore) <= 0.01) {
                $best[] = $option;
            }
        }

        $pick = $best[$random->nextInt(0, count($best) - 1)];

        return OpponentIntent::forMove($pick['slot'], $pick['move']);
    }

    /**
     * @return list<DefenseType>
     */
    private function affordableDefenses(CombatantSnapshot $self, BattleRules $rules): array
    {
        $out = [];
        foreach (DefenseType::cases() as $defense) {
            $cost = $rules->defenseStaminaCosts[$defense->value] ?? 0;
            if ($self->staminaCurrent >= $cost) {
                $out[] = $defense;
            }
        }

        return $out;
    }
}
