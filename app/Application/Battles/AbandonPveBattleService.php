<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Domain\Battle\BattleEvent;
use App\Domain\Battle\BattleEventType;
use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Battle\Models\PveBattle;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;

/**
 * Ends an active PvE battle as a player loss (leave / forfeit / replaced by a new start).
 */
final class AbandonPveBattleService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CompletePveBattleService $complete,
        private readonly BattleStateMapper $mapper,
    ) {}

    /**
     * @return array{battle: PveBattle, reward: ?array}
     */
    public function abandon(User $user, string $battleId): array
    {
        return $this->db->transaction(function () use ($user, $battleId): array {
            /** @var PveBattle|null $battle */
            $battle = PveBattle::query()->whereKey($battleId)->lockForUpdate()->first();
            if ($battle === null || (int) $battle->user_id !== (int) $user->id) {
                throw PveBattleException::notFound();
            }

            if ($battle->isFinished()) {
                $reward = $this->complete->completeIfNeeded($user, $battle);

                return ['battle' => $battle->fresh(['combatants.moves', 'events']), 'reward' => $reward];
            }

            $this->finishAsLoss($battle);
            $reward = $this->complete->completeIfNeeded($user, $battle->fresh());

            return ['battle' => $battle->fresh(['combatants.moves', 'events']), 'reward' => $reward];
        });
    }

    /**
     * Forfeit every active battle for the user (used before starting a new match).
     *
     * @return list<string> Abandoned battle ids
     */
    public function abandonAllActive(User $user): array
    {
        return $this->db->transaction(function () use ($user): array {
            $active = PveBattle::query()
                ->where('user_id', $user->id)
                ->where('status', PveBattle::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            $ids = [];
            foreach ($active as $battle) {
                $this->finishAsLoss($battle);
                $this->complete->completeIfNeeded($user, $battle->fresh());
                $ids[] = (string) $battle->id;
            }

            return $ids;
        });
    }

    private function finishAsLoss(PveBattle $battle): void
    {
        $sequence = (int) ($battle->events()->max('sequence') ?? 0);

        $battle->status = PveBattle::STATUS_FINISHED;
        $battle->phase = BattlePhase::Finished->value;
        $battle->outcome = BattleRewardPolicy::OUTCOME_LOSS;
        $battle->end_reason = PveBattle::END_REASON_ABANDONED;
        $battle->opponent_pending_action = null;
        $battle->version = (int) $battle->version + 1;
        $battle->finished_at = Carbon::now();
        $battle->save();

        $this->mapper->persistEvents($battle, [
            new BattleEvent(
                $sequence + 1,
                BattleEventType::BattleFinished,
                null,
                null,
                [
                    'outcome' => BattleRewardPolicy::OUTCOME_LOSS,
                    'reason' => 'abandoned',
                    'winner' => 'opponent',
                    'round' => (int) $battle->round,
                ],
            ),
        ]);
    }
}
