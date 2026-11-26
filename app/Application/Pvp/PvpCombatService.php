<?php

declare(strict_types=1);

namespace App\Application\Pvp;

use App\Application\Battles\BattleRewardService;
use App\Application\Battles\CombatantFactory;
use App\Application\Battles\PveRulesProvider;
use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\Pvp\PvpBattlePhase;
use App\Domain\Battle\Pvp\PvpBattleState;
use App\Domain\Battle\Pvp\PvpTurnEngine;
use App\Domain\Battle\Random\SeededRandomSource;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpBattleAction;
use App\Domain\Pvp\Models\PvpBattleCombatant;
use App\Domain\Pvp\Models\PvpBattleCombatantMove;
use App\Domain\Pvp\Models\PvpBattleEvent;
use App\Domain\Pvp\Models\PvpBattleReward;
use App\Domain\Pvp\Models\PvpBattleSelection;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PvpCombatService
{
    public function __construct(
        private readonly PveRulesProvider $rules,
        private readonly CombatantFactory $combatants,
        private readonly PvpTurnEngine $turns,
        private readonly PvpBattleStateMapper $mapper,
        private readonly PvpBattlePresenter $presenter,
        private readonly BattleRewardService $rewards,
        private readonly PvpRealtimePublisher $publisher,
        private readonly BattleRewardPolicy $rewardPolicy,
    ) {}

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function selectCreature(User $user, string $roomId, int $ownedCreatureId, int $expectedRoomVersion, ?string $idempotencyKey): array
    {
        return DB::transaction(function () use ($user, $roomId, $ownedCreatureId, $expectedRoomVersion): array {
            $room = PvpRoom::query()->whereKey($roomId)->lockForUpdate()->first();
            if ($room === null || ! $room->isOpen()) {
                throw PvpLobbyException::notFound();
            }
            if ($room->status !== PvpRoom::STATUS_READY) {
                throw PvpLobbyException::roomNotReady();
            }
            $this->assertActiveMember($room, $user);
            if ((int) $room->version !== $expectedRoomVersion) {
                throw PvpLobbyException::versionConflict();
            }

            $owned = OwnedCreature::query()
                ->whereKey($ownedCreatureId)
                ->where('user_id', $user->id)
                ->first();
            if ($owned === null) {
                throw new PvpLobbyException('PVP_CREATURE_NOT_OWNED', __('api.pvp.creature_not_owned'), 422);
            }

            $selection = PvpBattleSelection::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($selection !== null && $selection->is_locked) {
                throw PvpLobbyException::selectionLocked();
            }

            if ($selection === null) {
                $selection = new PvpBattleSelection([
                    'id' => (string) Str::uuid(),
                    'room_id' => $room->id,
                    'user_id' => $user->id,
                    'selected_at' => now(),
                    'version' => 1,
                ]);
            }

            $selection->owned_creature_id = $owned->id;
            $selection->selected_at = now();
            $selection->is_locked = false;
            $selection->locked_at = null;
            $selection->version = (int) $selection->version + 1;
            $selection->save();

            $room->version = (int) $room->version + 1;
            $room->save();

            return [
                'status' => 200,
                'body' => ['data' => $this->presentRoomWithSelections($room->fresh(), $user)],
            ];
        });
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function ready(User $user, string $roomId, int $expectedRoomVersion, ?string $idempotencyKey): array
    {
        $result = DB::transaction(function () use ($user, $roomId, $expectedRoomVersion): array {
            $room = PvpRoom::query()->whereKey($roomId)->lockForUpdate()->first();
            if ($room === null || ! $room->isOpen()) {
                throw PvpLobbyException::notFound();
            }
            if ($room->status === PvpRoom::STATUS_IN_BATTLE) {
                $existing = PvpBattle::query()->where('room_id', $room->id)->first();
                if ($existing !== null) {
                    return ['battle' => $existing, 'created' => false, 'room' => $room];
                }
            }
            if ($room->status !== PvpRoom::STATUS_READY) {
                throw PvpLobbyException::roomNotReady();
            }
            $this->assertActiveMember($room, $user);
            if ((int) $room->version !== $expectedRoomVersion) {
                throw PvpLobbyException::versionConflict();
            }

            $selection = PvpBattleSelection::query()
                ->where('room_id', $room->id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if ($selection === null) {
                throw new PvpLobbyException('PVP_SELECTION_REQUIRED', __('api.pvp.selection_required'), 422);
            }
            if (! $selection->is_locked) {
                $selection->is_locked = true;
                $selection->locked_at = now();
                $selection->version = (int) $selection->version + 1;
                $selection->save();
            }

            $activeMembers = PvpRoomMember::query()
                ->where('room_id', $room->id)
                ->whereNull('left_at')
                ->lockForUpdate()
                ->orderBy('user_id')
                ->get();
            if ($activeMembers->count() !== 2) {
                throw PvpLobbyException::roomNotReady();
            }

            $selections = PvpBattleSelection::query()
                ->where('room_id', $room->id)
                ->whereIn('user_id', $activeMembers->pluck('user_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('user_id');

            $bothLocked = $activeMembers->every(
                fn (PvpRoomMember $m) => ($selections->get($m->user_id)?->is_locked ?? false) === true
            );

            $room->version = (int) $room->version + 1;
            $room->save();

            if (! $bothLocked) {
                return ['battle' => null, 'created' => false, 'room' => $room];
            }

            $existing = PvpBattle::query()->where('room_id', $room->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return ['battle' => $existing, 'created' => false, 'room' => $room];
            }

            foreach ($activeMembers as $member) {
                $u = User::query()->with('progress')->findOrFail($member->user_id);
                if ((int) ($u->progress?->level ?? 1) < (int) config('game.battle.pvp_min_trainer_level', 6)) {
                    throw PvpLobbyException::notEligible();
                }
            }

            $hostId = (int) $room->host_user_id;
            $guestId = (int) $activeMembers->first(fn (PvpRoomMember $m) => (int) $m->user_id !== $hostId)->user_id;
            $hostSelection = $selections->get($hostId);
            $guestSelection = $selections->get($guestId);

            $hostOwned = OwnedCreature::query()->with('creature.types')->whereKey($hostSelection->owned_creature_id)->where('user_id', $hostId)->firstOrFail();
            $guestOwned = OwnedCreature::query()->with('creature.types')->whereKey($guestSelection->owned_creature_id)->where('user_id', $guestId)->firstOrFail();

            $rules = $this->rules->rules();
            $rulesSnapshot = $this->rules->rulesArray();
            $rewardSnapshot = $this->rewardPolicy->rewardsForMode(BattleRewardPolicy::MODE_PVP);

            $hostSnap = $this->combatants->fromOwned($hostOwned, CombatSide::Player);
            $guestSnap = $this->combatants->fromOwned($guestOwned, CombatSide::Opponent);

            $seed = (string) Str::uuid();
            $rng = SeededRandomSource::fromSeed((int) sprintf('%u', crc32($seed)), 0);
            $hostFirst = $rng->nextInt(1, 2) === 1;
            $attackerId = $hostFirst ? $hostId : $guestId;
            $defenderId = $hostFirst ? $guestId : $hostId;

            $battleId = (string) Str::uuid();
            $battle = PvpBattle::query()->create([
                'id' => $battleId,
                'room_id' => $room->id,
                'status' => PvpBattle::STATUS_ACTIVE,
                'phase' => PvpBattle::PHASE_AWAITING_ATTACK,
                'turn_number' => 1,
                'version' => 1,
                'host_user_id' => $hostId,
                'guest_user_id' => $guestId,
                'current_attacker_user_id' => $attackerId,
                'current_defender_user_id' => $defenderId,
                'pending_attack_snapshot' => null,
                'rng_seed' => $seed,
                'rng_cursor' => $rng->cursor(),
                'rules_version' => $rules->rulesVersion,
                'rules_snapshot' => $rulesSnapshot,
                'reward_snapshot' => $rewardSnapshot,
                'last_event_sequence' => 1,
                'started_at' => now(),
            ]);

            $this->persistCombatant($battle, $hostSnap, (int) $hostOwned->creature_id, (int) $hostOwned->id, $hostId);
            $this->persistCombatant($battle, $guestSnap, (int) $guestOwned->creature_id, (int) $guestOwned->id, $guestId);

            PvpBattleEvent::query()->create([
                'battle_id' => $battle->id,
                'sequence' => 1,
                'type' => 'battle_started',
                'actor' => null,
                'target' => null,
                'payload' => [
                    'first_attacker_user_id' => $attackerId,
                    'host_user_id' => $hostId,
                    'guest_user_id' => $guestId,
                ],
                'occurred_at' => now(),
            ]);

            $room->status = PvpRoom::STATUS_IN_BATTLE;
            $room->save();

            return ['battle' => $battle->fresh(['combatants.moves']), 'created' => true, 'room' => $room->fresh()];
        });

        $room = $result['room'];
        $this->publisher->publish('pvp.room.changed', [
            'room_id' => $room->id,
            'version' => (int) $room->version,
        ], targetRoomId: $room->id);

        if ($result['battle'] !== null) {
            $battle = $result['battle'];
            $this->publisher->publish('pvp.battle.changed', [
                'room_id' => $room->id,
                'battle_id' => $battle->id,
                'version' => (int) $battle->version,
                'last_event_sequence' => (int) $battle->last_event_sequence,
            ], targetRoomId: $room->id);
        }

        $body = ['data' => $this->presentRoomWithSelections($room, $user)];
        if ($result['battle'] !== null) {
            $body['data']['battle'] = $this->presenter->present($result['battle'], $user);
        }

        return ['status' => 200, 'body' => $body];
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function getBattle(User $user, string $battleId): array
    {
        $battle = PvpBattle::query()->with(['combatants.moves'])->whereKey($battleId)->first();
        if ($battle === null || ! $battle->isParticipant((int) $user->id)) {
            throw PvpLobbyException::notFound();
        }

        return ['status' => 200, 'body' => ['data' => $this->presenter->present($battle, $user)]];
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function listEvents(User $user, string $battleId, int $afterSequence = 0): array
    {
        $battle = PvpBattle::query()->whereKey($battleId)->first();
        if ($battle === null || ! $battle->isParticipant((int) $user->id)) {
            throw PvpLobbyException::notFound();
        }

        $events = PvpBattleEvent::query()
            ->where('battle_id', $battle->id)
            ->where('sequence', '>', $afterSequence)
            ->orderBy('sequence')
            ->limit(200)
            ->get()
            ->map(fn (PvpBattleEvent $e) => [
                'sequence' => (int) $e->sequence,
                'type' => $e->type,
                'actor' => $e->actor,
                'target' => $e->target,
                'payload' => $e->payload,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ])
            ->all();

        return ['status' => 200, 'body' => ['data' => $events]];
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function attack(
        User $user,
        string $battleId,
        ?int $moveId,
        bool $basicStrike,
        bool $recover,
        int $expectedVersion,
        string $idempotencyKey,
    ): array {
        $modes = (int) $basicStrike + (int) $recover + ($moveId !== null ? 1 : 0);
        if ($modes !== 1) {
            throw new PvpLobbyException('PVP_INVALID_ATTACK', 'Provide exactly one of move_id, basic_strike, or recover.', 422);
        }

        $payload = [
            'move_id' => $moveId,
            'basic_strike' => $basicStrike,
            'recover' => $recover,
            'expected_version' => $expectedVersion,
        ];

        $out = DB::transaction(function () use ($user, $battleId, $moveId, $basicStrike, $recover, $expectedVersion, $idempotencyKey, $payload): array {
            $battle = $this->lockBattleWithRoom($battleId);
            $this->assertParticipant($battle, $user);

            $replay = $this->replayAction($battle, $user, $idempotencyKey, $payload);
            if ($replay !== null) {
                return $replay;
            }

            if ($battle->isFinished()) {
                throw PvpLobbyException::alreadyFinished();
            }
            if ((int) $battle->version !== $expectedVersion) {
                throw PvpLobbyException::versionConflict();
            }
            if ($battle->phase !== PvpBattle::PHASE_AWAITING_ATTACK) {
                throw PvpLobbyException::phaseConflict();
            }
            if ((int) $battle->current_attacker_user_id !== (int) $user->id) {
                throw PvpLobbyException::wrongTurn();
            }

            $state = $this->mapper->toDomain($battle);
            $rngCursor = (int) $battle->rng_cursor;

            try {
                if ($recover) {
                    $transition = $this->turns->recoverSkip($state, (int) $user->id, $rngCursor);
                } else {
                    $attacker = $state->combatantForUser((int) $user->id);
                    $moveSlot = null;
                    $move = $state->rules->basicStrikeMove();
                    if (! $basicStrike) {
                        $moveSlot = $this->slotForMoveId($attacker->moves, (int) $moveId);
                        if ($moveSlot === null) {
                            throw new DomainException('Move is not available.');
                        }
                        $move = $attacker->move($moveSlot);
                    }
                    $transition = $this->turns->commitAttack($state, (int) $user->id, $moveSlot, $move, $rngCursor);
                }
            } catch (DomainException $e) {
                throw new PvpLobbyException('PVP_INVALID_ATTACK', $e->getMessage(), 422);
            }

            $this->mapper->applyTransition($battle, $transition);
            $battle = $battle->fresh(['combatants.moves']);

            $this->storeAction($battle, $user, 'attack', $idempotencyKey, $expectedVersion, $payload, [
                'battle' => $this->presenter->present($battle, $user),
            ]);

            return ['battle' => $battle, 'rewards' => null];
        });

        $this->publishBattleChanged($out['battle']);

        return [
            'status' => 200,
            'body' => ['data' => $this->presenter->present($out['battle']->fresh(['combatants.moves']), $user)],
        ];
    }

    /**
     * @return array{status:int, body:array<string, mixed>}
     */
    public function defend(User $user, string $battleId, string $defense, int $expectedVersion, string $idempotencyKey): array
    {
        $defenseType = DefenseType::tryFrom($defense)
            ?? throw new PvpLobbyException('PVP_INVALID_DEFENSE', 'Invalid defense.', 422);

        $payload = ['defense' => $defense, 'expected_version' => $expectedVersion];

        $out = DB::transaction(function () use ($user, $battleId, $defenseType, $expectedVersion, $idempotencyKey, $payload): array {
            $battle = $this->lockBattleWithRoom($battleId);
            $this->assertParticipant($battle, $user);

            $replay = $this->replayAction($battle, $user, $idempotencyKey, $payload);
            if ($replay !== null) {
                return $replay;
            }

            if ($battle->isFinished()) {
                throw PvpLobbyException::alreadyFinished();
            }
            if ((int) $battle->version !== $expectedVersion) {
                throw PvpLobbyException::versionConflict();
            }
            if ($battle->phase !== PvpBattle::PHASE_AWAITING_DEFENSE) {
                throw PvpLobbyException::phaseConflict();
            }
            if ((int) $battle->current_defender_user_id !== (int) $user->id) {
                throw PvpLobbyException::wrongTurn();
            }

            $state = $this->mapper->toDomain($battle);
            $rng = SeededRandomSource::fromSeed(
                (int) sprintf('%u', crc32((string) $battle->rng_seed)),
                (int) $battle->rng_cursor,
            );
            $chart = $this->rules->chart($state->rules->rulesVersion);

            try {
                $transition = $this->turns->resolveDefense($state, (int) $user->id, $defenseType, $rng, $chart);
            } catch (DomainException $e) {
                throw new PvpLobbyException('PVP_INVALID_DEFENSE', $e->getMessage(), 422);
            }

            $this->mapper->applyTransition($battle, $transition);
            $battle = $battle->fresh(['combatants.moves']);

            $rewardPayload = null;
            if ($transition->nextState->isFinished()) {
                $rewardPayload = $this->finalizeRewardsAndRoom($battle);
                $battle = $battle->fresh(['combatants.moves']);
            }

            $this->storeAction($battle, $user, 'defend', $idempotencyKey, $expectedVersion, $payload, [
                'battle' => $this->presenter->present($battle, $user),
                'rewards' => $rewardPayload,
            ]);

            return ['battle' => $battle, 'rewards' => $rewardPayload];
        });

        $this->publishBattleChanged($out['battle']);

        return [
            'status' => 200,
            'body' => [
                'data' => [
                    'battle' => $this->presenter->present($out['battle']->fresh(['combatants.moves']), $user),
                    'rewards' => $out['rewards'],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function finalizeRewardsAndRoom(PvpBattle $battle): ?array
    {
        if ($battle->rewards_processed) {
            return PvpBattleReward::query()->where('battle_id', $battle->id)->get()
                ->map(fn (PvpBattleReward $r) => [
                    'user_id' => (int) $r->user_id,
                    'outcome' => $r->outcome,
                    'xp_awarded' => (int) $r->xp_awarded,
                ])->all();
        }

        $winner = User::query()->with('progress')->findOrFail((int) $battle->winner_user_id);
        $loser = User::query()->with('progress')->findOrFail((int) $battle->loser_user_id);

        $winnerResult = $this->rewards->awardForCompletedMatch($winner, [
            'match_id' => (string) $battle->id,
            'mode' => BattleRewardPolicy::MODE_PVP,
            'outcome' => BattleRewardPolicy::OUTCOME_WIN,
            'verified' => true,
        ]);
        $loserResult = $this->rewards->awardForCompletedMatch($loser, [
            'match_id' => (string) $battle->id,
            'mode' => BattleRewardPolicy::MODE_PVP,
            'outcome' => BattleRewardPolicy::OUTCOME_LOSS,
            'verified' => true,
        ]);

        PvpBattleReward::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'user_id' => $winner->id,
            'outcome' => BattleRewardPolicy::OUTCOME_WIN,
            'xp_awarded' => (int) $winnerResult->event->amount,
            'trainer_levels_gained' => (int) $winnerResult->levelsGained,
            'idempotency_key' => sprintf('battle:pvp:%s:%d', $battle->id, $winner->id),
        ]);
        PvpBattleReward::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'user_id' => $loser->id,
            'outcome' => BattleRewardPolicy::OUTCOME_LOSS,
            'xp_awarded' => (int) $loserResult->event->amount,
            'trainer_levels_gained' => (int) $loserResult->levelsGained,
            'idempotency_key' => sprintf('battle:pvp:%s:%d', $battle->id, $loser->id),
        ]);

        $battle->rewards_processed = true;
        $battle->save();

        $room = PvpRoom::query()->whereKey($battle->room_id)->lockForUpdate()->first();
        if ($room !== null && $room->status === PvpRoom::STATUS_IN_BATTLE) {
            $room->status = PvpRoom::STATUS_CLOSED;
            $room->closed_reason = PvpRoom::CLOSED_BATTLE_COMPLETED;
            $room->closed_at = now();
            $room->version = (int) $room->version + 1;
            $room->save();

            $this->publisher->publish('pvp.room.changed', [
                'room_id' => $room->id,
                'version' => (int) $room->version,
            ], targetRoomId: $room->id);
        }

        return [
            ['user_id' => (int) $winner->id, 'outcome' => 'win', 'xp_awarded' => (int) $winnerResult->event->amount],
            ['user_id' => (int) $loser->id, 'outcome' => 'loss', 'xp_awarded' => (int) $loserResult->event->amount],
        ];
    }

    private function lockBattleWithRoom(string $battleId): PvpBattle
    {
        $battle = PvpBattle::query()->whereKey($battleId)->first();
        if ($battle === null) {
            throw PvpLobbyException::notFound();
        }
        PvpRoom::query()->whereKey($battle->room_id)->lockForUpdate()->first();

        return PvpBattle::query()->whereKey($battleId)->lockForUpdate()->firstOrFail();
    }

    private function assertParticipant(PvpBattle $battle, User $user): void
    {
        if (! $battle->isParticipant((int) $user->id)) {
            throw PvpLobbyException::notFound();
        }
    }

    private function assertActiveMember(PvpRoom $room, User $user): void
    {
        $ok = PvpRoomMember::query()
            ->where('room_id', $room->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();
        if (! $ok) {
            throw PvpLobbyException::notFound();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{battle: PvpBattle, rewards: mixed}|null
     */
    private function replayAction(PvpBattle $battle, User $user, string $idempotencyKey, array $payload): ?array
    {
        $existing = PvpBattleAction::query()
            ->where('battle_id', $battle->id)
            ->where('actor_user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
        if ($existing === null) {
            return null;
        }
        if ((array) $existing->input_snapshot !== $payload) {
            throw PvpLobbyException::idempotencyConflict();
        }
        $snap = (array) ($existing->result_snapshot ?? []);

        return [
            'battle' => $battle->fresh(['combatants.moves']),
            'rewards' => $snap['rewards'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $result
     */
    private function storeAction(
        PvpBattle $battle,
        User $user,
        string $type,
        string $idempotencyKey,
        int $expectedVersion,
        array $payload,
        array $result,
    ): void {
        PvpBattleAction::query()->create([
            'id' => (string) Str::uuid(),
            'battle_id' => $battle->id,
            'actor_user_id' => $user->id,
            'action_type' => $type,
            'idempotency_key' => $idempotencyKey,
            'expected_version' => $expectedVersion,
            'resolved_version' => (int) $battle->version,
            'input_snapshot' => $payload,
            'result_snapshot' => $result,
        ]);
    }

    private function persistCombatant(
        PvpBattle $battle,
        \App\Domain\Battle\CombatantSnapshot $snapshot,
        int $creatureId,
        int $ownedCreatureId,
        int $userId,
    ): void {
        $row = PvpBattleCombatant::query()->create([
            'battle_id' => $battle->id,
            'user_id' => $userId,
            'side' => $snapshot->side->value,
            'creature_id' => $creatureId,
            'owned_creature_id' => $ownedCreatureId,
            'name' => $snapshot->name,
            'slug' => $snapshot->slug,
            'image_ref' => \App\Domain\Creatures\Models\Creature::query()->whereKey($creatureId)->value('image_ref'),
            'level' => $snapshot->level,
            'hp_current' => $snapshot->hpCurrent,
            'hp_max' => $snapshot->hpMax,
            'stamina_current' => $snapshot->staminaCurrent,
            'stamina_max' => $snapshot->staminaMax,
            'stats_snapshot' => $snapshot->stats,
            'types_snapshot' => $snapshot->types,
        ]);

        foreach ($snapshot->moves as $slot => $move) {
            PvpBattleCombatantMove::query()->create([
                'pvp_battle_combatant_id' => $row->id,
                'move_id' => $move->id,
                'slot' => $slot,
                'pp_current' => $move->ppCurrent,
                'pp_max' => $move->ppMax,
                'stamina_cost' => $move->staminaCost,
                'move_snapshot' => $move->toArray(),
            ]);
        }
    }

    /**
     * @param  list<\App\Domain\Battle\MoveSnapshot>  $moves
     */
    private function slotForMoveId(array $moves, int $moveId): ?int
    {
        foreach ($moves as $slot => $move) {
            if ($move->id === $moveId) {
                return (int) $slot;
            }
        }

        return null;
    }

    private function publishBattleChanged(PvpBattle $battle): void
    {
        $this->publisher->publish('pvp.battle.changed', [
            'room_id' => $battle->room_id,
            'battle_id' => $battle->id,
            'version' => (int) $battle->version,
            'last_event_sequence' => (int) $battle->last_event_sequence,
        ], targetRoomId: (string) $battle->room_id);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRoomWithSelections(PvpRoom $room, User $viewer): array
    {
        $room->loadMissing(['selections', 'battle', 'host.profile', 'activeMembers.user.profile']);
        $selections = $room->selections ?? collect();
        $mine = $selections->firstWhere('user_id', $viewer->id);
        $bothLocked = $selections->count() >= 2 && $selections->every(fn ($s) => (bool) $s->is_locked);

        return [
            'id' => $room->id,
            'status' => $room->status,
            'version' => (int) $room->version,
            'my_selection' => $mine === null ? null : [
                'owned_creature_id' => (int) $mine->owned_creature_id,
                'locked' => (bool) $mine->is_locked,
            ],
            'selections_revealed' => $bothLocked
                ? $selections->map(fn ($s) => [
                    'user_id' => (int) $s->user_id,
                    'owned_creature_id' => (int) $s->owned_creature_id,
                    'locked' => (bool) $s->is_locked,
                ])->values()->all()
                : null,
            'battle_id' => $room->battle?->id,
            'phase_note' => $room->status === PvpRoom::STATUS_IN_BATTLE
                ? 'Battle in progress.'
                : 'Private lobby ready — select a creature and lock ready.',
        ];
    }
}
