<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Domain\Battle\Models\BattleEventRecord;
use App\Domain\Battle\Models\PveBattle;
use App\Models\User;

final class GetPveBattleService
{
    public function ownedBattle(User $user, string $battleId): PveBattle
    {
        /** @var PveBattle|null $battle */
        $battle = PveBattle::query()
            ->whereKey($battleId)
            ->with(['combatants.moves', 'events'])
            ->first();

        if ($battle === null || (int) $battle->user_id !== (int) $user->id) {
            throw PveBattleException::notFound();
        }

        return $battle;
    }

    /**
     * @return list<BattleEventRecord>
     */
    public function eventsAfter(User $user, string $battleId, int $after = 0): array
    {
        $battle = $this->ownedBattle($user, $battleId);

        return BattleEventRecord::query()
            ->where('battle_id', $battle->id)
            ->where('sequence', '>', max(0, $after))
            ->orderBy('sequence')
            ->limit(200)
            ->get()
            ->all();
    }
}
