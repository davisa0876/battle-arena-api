<?php

namespace App\Domain\Battle;

use App\Domain\Progression\Models\PlayerProgress;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Mode eligibility from trainer level. Battle modes are not playable yet;
 * this policy exists so future endpoints can reject under-level players.
 */
class BattleEligibilityPolicy
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function pveMinTrainerLevel(): int
    {
        return max(1, (int) $this->config->get('game.battle.pve_min_trainer_level', 1));
    }

    public function pvpMinTrainerLevel(): int
    {
        return max(1, (int) $this->config->get('game.battle.pvp_min_trainer_level', 6));
    }

    public function canEnterPve(int $trainerLevel): bool
    {
        return $trainerLevel >= $this->pveMinTrainerLevel();
    }

    public function canEnterPvp(int $trainerLevel): bool
    {
        return $trainerLevel >= $this->pvpMinTrainerLevel();
    }

    public function canEnterPvpForUser(User $user): bool
    {
        $level = (int) ($user->progress?->level ?? 1);

        return $this->canEnterPvp($level);
    }

    public function canEnterPvpForProgress(PlayerProgress $progress): bool
    {
        return $this->canEnterPvp((int) $progress->level);
    }
}
