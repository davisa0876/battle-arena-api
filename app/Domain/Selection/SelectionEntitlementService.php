<?php

namespace App\Domain\Selection;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\TrainerProgressionService;
use App\Domain\Selection\Models\CreatureSelectionAcquisition;
use App\Models\User;

/**
 * Computes remaining selection slots and PvP unlock from ledger + policies.
 */
class SelectionEntitlementService
{
    public function __construct(
        private readonly CreatureSelectionPolicy $selectionPolicy,
        private readonly BattleEligibilityPolicy $battleEligibility,
        private readonly TrainerProgressionService $progression,
    ) {}

    /**
     * @return list<array{evolution_stage: int, total: int, used: int, remaining: int}>
     */
    public function allowancesFor(User $user, ?PlayerProgress $progress = null): array
    {
        $progress ??= $user->progress;
        $level = $progress !== null
            ? $this->progression->levelForTotalXp((int) $progress->total_xp)
            : 1;

        $usedByStage = CreatureSelectionAcquisition::query()
            ->where('user_id', $user->id)
            ->selectRaw('original_evolution_stage, COUNT(*) as used_count')
            ->groupBy('original_evolution_stage')
            ->pluck('used_count', 'original_evolution_stage');

        $rows = [];
        foreach ($this->selectionPolicy->allowanceTotals($level) as $row) {
            $stage = $row['evolution_stage'];
            $total = $row['total'];
            $used = (int) ($usedByStage[$stage] ?? 0);
            $rows[] = [
                'evolution_stage' => $stage,
                'total' => $total,
                'used' => $used,
                'remaining' => max(0, $total - $used),
            ];
        }

        return $rows;
    }

    public function remainingForStage(User $user, int $evolutionStage, ?PlayerProgress $progress = null): int
    {
        foreach ($this->allowancesFor($user, $progress) as $row) {
            if ($row['evolution_stage'] === $evolutionStage) {
                return $row['remaining'];
            }
        }

        return 0;
    }

    public function pvpLevelUnlocked(User $user, ?PlayerProgress $progress = null): bool
    {
        $progress ??= $user->progress;
        $level = $progress !== null
            ? $this->progression->levelForTotalXp((int) $progress->total_xp)
            : 1;

        return $this->battleEligibility->canEnterPvp($level);
    }

    public function consumedCount(User $user, int $evolutionStage): int
    {
        return CreatureSelectionAcquisition::query()
            ->where('user_id', $user->id)
            ->where('original_evolution_stage', $evolutionStage)
            ->count();
    }
}
