<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Battle\Models\PveBattle;
use App\Models\User;

final class PveBattleStatsService
{
    /**
     * @return array{wins: int, losses: int, give_ups: int}
     */
    public function forUser(User $user): array
    {
        return $this->forUserId((int) $user->id);
    }

    /**
     * @return array{wins: int, losses: int, give_ups: int}
     */
    public function forUserId(int $userId): array
    {
        $finished = PveBattle::query()
            ->where('user_id', $userId)
            ->where('status', PveBattle::STATUS_FINISHED);

        $wins = (clone $finished)
            ->where('outcome', BattleRewardPolicy::OUTCOME_WIN)
            ->count();

        $giveUps = (clone $finished)
            ->where('end_reason', PveBattle::END_REASON_ABANDONED)
            ->count();

        $losses = (clone $finished)
            ->where('outcome', BattleRewardPolicy::OUTCOME_LOSS)
            ->where(function ($query): void {
                $query->whereNull('end_reason')
                    ->orWhere('end_reason', '!=', PveBattle::END_REASON_ABANDONED);
            })
            ->count();

        return [
            'wins' => $wins,
            'losses' => $losses,
            'give_ups' => $giveUps,
        ];
    }
}
