<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Battle\Models\PveBattle;
use App\Models\User;

/**
 * Awards trainer XP exactly once for a finished, server-verified PvE match.
 */
final class CompletePveBattleService
{
    public function __construct(
        private readonly BattleRewardService $rewards,
    ) {}

    public function completeIfNeeded(User $user, PveBattle $battle): ?array
    {
        if (! $battle->isFinished() || $battle->outcome === null) {
            return null;
        }

        // Give-ups / forfeits count as a loss but never grant trainer XP.
        if ($battle->end_reason === PveBattle::END_REASON_ABANDONED) {
            if (! $battle->reward_processed) {
                $battle->reward_processed = true;
                $battle->xp_awarded = 0;
                $battle->trainer_levels_gained = 0;
                $battle->save();
            }

            return null;
        }

        if ($battle->reward_processed) {
            return [
                'xp_awarded' => (int) $battle->xp_awarded,
                'trainer_levels_gained' => (int) ($battle->trainer_levels_gained ?? 0),
                'duplicate' => true,
            ];
        }

        $result = $this->rewards->awardForCompletedMatch($user, [
            'match_id' => (string) $battle->id,
            'mode' => BattleRewardPolicy::MODE_PVE,
            'outcome' => (string) $battle->outcome,
            'verified' => true,
        ]);

        $battle->reward_processed = true;
        $battle->xp_awarded = (int) $result->event->amount;
        $battle->trainer_levels_gained = (int) $result->levelsGained;
        $battle->save();

        return [
            'xp_awarded' => (int) $battle->xp_awarded,
            'trainer_levels_gained' => (int) $battle->trainer_levels_gained,
            'duplicate' => $result->wasDuplicate,
        ];
    }
}
