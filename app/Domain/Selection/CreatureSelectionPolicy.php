<?php

namespace App\Domain\Selection;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Authoritative creature-selection entitlements by trainer level and evolution stage.
 *
 * Selection allowance is about acquiring catalog creatures into the collection.
 * It is not battle-team slot count and not owned-creature level.
 */
class CreatureSelectionPolicy
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function stage1Allowance(int $trainerLevel): int
    {
        $level = max(1, $trainerLevel);
        $cap = max(0, (int) $this->config->get('game.selection.stage_1.allowance_cap', 10));

        return min($level, $cap);
    }

    /**
     * Stage-2 allowance through the configured `defined_through_level`.
     * Default: unlock at 10 with 1 slot, then +1 per level → max(0, level - 9).
     */
    public function stage2Allowance(int $trainerLevel): int
    {
        $level = max(1, $trainerLevel);
        $unlock = max(1, (int) $this->config->get('game.selection.stage_2.unlock_level', 10));
        $atUnlock = max(0, (int) $this->config->get('game.selection.stage_2.allowance_at_unlock', 1));
        $increment = max(0, (int) $this->config->get('game.selection.stage_2.increment_per_level', 1));
        $through = max($unlock, (int) $this->config->get('game.selection.stage_2.defined_through_level', 20));

        if ($level < $unlock) {
            return 0;
        }

        $effectiveLevel = min($level, $through);
        $stepsAboveUnlock = $effectiveLevel - $unlock;

        return $atUnlock + ($stepsAboveUnlock * $increment);
    }

    public function stage3Unlocked(): bool
    {
        $unlock = $this->config->get('game.selection.stage_3.unlock_level');

        return $unlock !== null && (int) $unlock > 0;
    }

    public function allowanceForStage(int $trainerLevel, int $evolutionStage): int
    {
        return match ($evolutionStage) {
            1 => $this->stage1Allowance($trainerLevel),
            2 => $this->stage2Allowance($trainerLevel),
            default => 0,
        };
    }

    public function canSelectStage(int $trainerLevel, int $evolutionStage): bool
    {
        if ($evolutionStage <= 0) {
            throw new InvalidArgumentException('Evolution stage must be positive.');
        }

        if ($evolutionStage >= 3) {
            return $this->stage3Unlocked()
                && $this->allowanceForStage($trainerLevel, $evolutionStage) > 0;
        }

        return $this->allowanceForStage($trainerLevel, $evolutionStage) > 0;
    }

    /**
     * @return list<array{evolution_stage: int, total: int}>
     */
    public function allowanceTotals(int $trainerLevel): array
    {
        return [
            ['evolution_stage' => 1, 'total' => $this->stage1Allowance($trainerLevel)],
            ['evolution_stage' => 2, 'total' => $this->stage2Allowance($trainerLevel)],
        ];
    }
}
