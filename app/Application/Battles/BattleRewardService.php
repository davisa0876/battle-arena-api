<?php

namespace App\Application\Battles;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Progression\TrainerProgressionService;
use App\Domain\Progression\XpAwardResult;
use App\Models\User;
use InvalidArgumentException;

/**
 * Awards trainer XP from a verified completed match result.
 * Clients must never supply the XP amount — only mode + outcome + match id.
 *
 * Battle engine is not implemented; this service is for future wiring and tests.
 */
class BattleRewardService
{
    public function __construct(
        private readonly BattleRewardPolicy $rewards,
        private readonly BattleEligibilityPolicy $eligibility,
        private readonly TrainerProgressionService $progression,
    ) {}

    /**
     * @param  array{match_id: string, mode: string, outcome: string, verified?: bool}  $result
     */
    public function awardForCompletedMatch(User $user, array $result): XpAwardResult
    {
        $matchId = trim((string) ($result['match_id'] ?? ''));
        $mode = mb_strtolower(trim((string) ($result['mode'] ?? '')));
        $outcome = mb_strtolower(trim((string) ($result['outcome'] ?? '')));
        $verified = (bool) ($result['verified'] ?? false);

        if ($matchId === '') {
            throw new InvalidArgumentException('A match id is required.');
        }

        if (! $verified) {
            throw new InvalidArgumentException('Only verified completed matches may award XP.');
        }

        if ($mode === BattleRewardPolicy::MODE_PVP && ! $this->eligibility->canEnterPvpForUser($user)) {
            throw new InvalidArgumentException('Trainer is not eligible for PvP rewards.');
        }

        if ($mode === BattleRewardPolicy::MODE_PVE && ! $this->eligibility->canEnterPve((int) ($user->progress?->level ?? 1))) {
            throw new InvalidArgumentException('Trainer is not eligible for PvE rewards.');
        }

        $amount = $this->rewards->xpFor($mode, $outcome);
        $idempotencyKey = sprintf('battle:%s:%s:%d', $mode, $matchId, $user->id);

        return $this->progression->awardXp(
            $user,
            $amount,
            'battle_'.$mode,
            $matchId,
            $idempotencyKey,
        );
    }
}
