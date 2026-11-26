<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use App\Domain\Pvp\Models\PvpBattle;
use App\Models\User;

final class PvpBattleStatsService
{
    /**
     * @return array{wins: int, losses: int, forfeits: int}
     */
    public function forUser(User $user): array
    {
        return $this->forUserId((int) $user->id);
    }

    /**
     * Career private-PvP totals for the signed-in trainer.
     *
     * @return array{wins: int, losses: int, forfeits: int}
     */
    public function forUserId(int $userId): array
    {
        $finished = PvpBattle::query()
            ->where('status', PvpBattle::STATUS_FINISHED)
            ->where(function ($query) use ($userId): void {
                $query->where('host_user_id', $userId)
                    ->orWhere('guest_user_id', $userId);
            });

        $wins = (clone $finished)->where('winner_user_id', $userId)->count();

        $forfeits = (clone $finished)
            ->where('end_reason', PvpBattle::END_REASON_FORFEIT)
            ->where('forfeiting_user_id', $userId)
            ->count();

        // Losses exclude self-forfeits so the forfeit chip is not double-counted as a "loss" pill.
        $losses = (clone $finished)
            ->where('loser_user_id', $userId)
            ->where(function ($query) use ($userId): void {
                $query->whereNull('forfeiting_user_id')
                    ->orWhere('forfeiting_user_id', '!=', $userId);
            })
            ->count();

        return [
            'wins' => $wins,
            'losses' => $losses,
            'forfeits' => $forfeits,
        ];
    }
}
