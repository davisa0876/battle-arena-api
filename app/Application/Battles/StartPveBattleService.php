<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Battle\BattleEvent;
use App\Domain\Battle\BattleEventType;
use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\BattleState;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\Models\BattleCombatant;
use App\Domain\Battle\Models\BattleCombatantMove;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final class StartPveBattleService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PveRulesProvider $rules,
        private readonly BattleEligibilityPolicy $eligibility,
        private readonly CombatantFactory $combatants,
        private readonly BattleStateMapper $mapper,
        private readonly AbandonPveBattleService $abandon,
    ) {}

    /**
     * @return array{battle: PveBattle, state: BattleState}
     */
    public function start(User $user, int $ownedCreatureId, ?string $difficulty = null): array
    {
        $user->loadMissing('progress');
        $level = (int) ($user->progress?->level ?? 1);
        if (! $this->eligibility->canEnterPve($level)) {
            throw PveBattleException::notEligible('Trainer is not eligible for PvE.');
        }

        $difficulty = mb_strtolower(trim((string) ($difficulty ?? $this->rules->defaultDifficulty())));
        if (! in_array($difficulty, $this->rules->difficulties(), true)) {
            throw PveBattleException::notEligible('Unsupported difficulty.');
        }

        return $this->db->transaction(function () use ($user, $ownedCreatureId, $difficulty): array {
            // Leaving mid-fight counts as a loss; never block a new start on a stale active match.
            $this->abandon->abandonAllActive($user);

            /** @var OwnedCreature|null $owned */
            $owned = OwnedCreature::query()
                ->where('user_id', $user->id)
                ->whereKey($ownedCreatureId)
                ->with('creature.types')
                ->first();
            if ($owned === null) {
                throw PveBattleException::notEligible('Owned creature not found.');
            }

            $playerSnap = $this->combatants->fromOwned($owned, CombatSide::Player);
            $opponentCreature = $this->pickOpponentCreature($owned->creature_id);
            $opponentSnap = $this->combatants->fromCreature(
                $opponentCreature,
                max(1, (int) $owned->level),
                CombatSide::Opponent,
            );

            $rules = $this->rules->rules();
            $battleId = (string) Str::uuid();
            $seed = bin2hex(random_bytes(16));

            $battle = PveBattle::query()->create([
                'id' => $battleId,
                'user_id' => $user->id,
                'owned_creature_id' => $owned->id,
                'status' => PveBattle::STATUS_ACTIVE,
                'phase' => BattlePhase::AwaitingPlayerAttack->value,
                'round' => 1,
                'version' => 1,
                'rules_version' => $rules->rulesVersion,
                'rules_snapshot' => $rules->toArray(),
                'difficulty' => $difficulty,
                'outcome' => null,
                'rng_seed' => $seed,
                'rng_cursor' => 0,
                'opponent_pending_action' => null,
                'reward_processed' => false,
            ]);

            $this->persistCombatant($battle, $playerSnap, $owned->creature_id, $owned->id);
            $this->persistCombatant($battle, $opponentSnap, $opponentCreature->id, null);

            $state = BattleState::start($battleId, $rules, $playerSnap, $opponentSnap);
            $this->mapper->persistEvents($battle, [
                new BattleEvent(1, BattleEventType::BattleStarted, null, null, [
                    'difficulty' => $difficulty,
                    'rules_version' => $rules->rulesVersion,
                ]),
            ]);
            // Align last sequence with the started event without bumping version.
            $battle->refresh();

            return ['battle' => $battle->fresh(['combatants.moves', 'events']), 'state' => $this->mapper->toDomain($battle->fresh(['combatants.moves']))];
        });
    }

    private function pickOpponentCreature(int $excludeCreatureId): Creature
    {
        $slugs = $this->rules->opponentCreatureSlugs();
        $query = Creature::query()->with('types')->whereIn('slug', $slugs);
        if ($excludeCreatureId > 0) {
            $query->where('id', '!=', $excludeCreatureId);
        }
        $pool = $query->get();
        if ($pool->isEmpty()) {
            $pool = Creature::query()->with('types')->whereIn('slug', $slugs)->get();
        }
        if ($pool->isEmpty()) {
            throw PveBattleException::notEligible('No opponent creatures are configured in the catalog.');
        }

        return $pool->random();
    }

    private function persistCombatant(
        PveBattle $battle,
        \App\Domain\Battle\CombatantSnapshot $snapshot,
        int $creatureId,
        ?int $ownedCreatureId,
    ): void {
        $creature = Creature::query()->findOrFail($creatureId);

        $row = BattleCombatant::query()->create([
            'battle_id' => $battle->id,
            'side' => $snapshot->side->value,
            'creature_id' => $creatureId,
            'owned_creature_id' => $ownedCreatureId,
            'name' => $snapshot->name,
            'slug' => $snapshot->slug,
            'image_ref' => $creature->image_ref,
            'cry_latest_url' => $creature->cry_latest_url,
            'cry_legacy_url' => $creature->cry_legacy_url,
            'level' => $snapshot->level,
            'hp_current' => $snapshot->hpCurrent,
            'hp_max' => $snapshot->hpMax,
            'stamina_current' => $snapshot->staminaCurrent,
            'stamina_max' => $snapshot->staminaMax,
            'stats_snapshot' => $snapshot->stats,
            'types_snapshot' => $snapshot->types,
        ]);

        foreach ($snapshot->moves as $slot => $move) {
            BattleCombatantMove::query()->create([
                'battle_combatant_id' => $row->id,
                'move_id' => $move->id,
                'slot' => $slot,
                'pp_current' => $move->ppCurrent,
                'pp_max' => $move->ppMax,
                'stamina_cost' => $move->staminaCost,
                'move_snapshot' => $move->toArray(),
            ]);
        }
    }
}
