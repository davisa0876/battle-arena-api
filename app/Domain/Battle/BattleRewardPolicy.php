<?php

namespace App\Domain\Battle;

use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;

/**
 * Configurable trainer XP rewards for verified completed matches.
 * Draws / forfeits / disconnects are intentionally undefined.
 */
class BattleRewardPolicy
{
    public const MODE_PVE = 'pve';

    public const MODE_PVP = 'pvp';

    public const OUTCOME_WIN = 'win';

    public const OUTCOME_LOSS = 'loss';

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * @return array{win: int, loss: int}
     */
    public function rewardsForMode(string $mode): array
    {
        $mode = mb_strtolower(trim($mode));

        if (! in_array($mode, [self::MODE_PVE, self::MODE_PVP], true)) {
            throw new InvalidArgumentException('Unsupported battle mode.');
        }

        $win = (int) $this->config->get("game.battle.rewards.{$mode}.win");
        $loss = (int) $this->config->get("game.battle.rewards.{$mode}.loss");

        return ['win' => $win, 'loss' => $loss];
    }

    public function xpFor(string $mode, string $outcome): int
    {
        $outcome = mb_strtolower(trim($outcome));

        if (! in_array($outcome, [self::OUTCOME_WIN, self::OUTCOME_LOSS], true)) {
            throw new InvalidArgumentException('Unsupported battle outcome.');
        }

        return $this->rewardsForMode($mode)[$outcome];
    }

    /**
     * @return array{pve: array{win: int, loss: int}, pvp: array{win: int, loss: int}}
     */
    public function allRewards(): array
    {
        return [
            'pve' => $this->rewardsForMode(self::MODE_PVE),
            'pvp' => $this->rewardsForMode(self::MODE_PVP),
        ];
    }
}
