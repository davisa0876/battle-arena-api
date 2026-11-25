<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Domain\Battle\BattleEngine;
use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\BattleTransition;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\Models\BattleAction;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Battle\Random\SeededRandomSource;
use App\Models\User;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final class SubmitPveDefenseService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BattleEngine $engine,
        private readonly PveRulesProvider $rules,
        private readonly BattleStateMapper $mapper,
        private readonly CompletePveBattleService $complete,
    ) {}

    /**
     * @return array{battle: PveBattle, events: list<array<string, mixed>>, reward: ?array}
     */
    public function submit(
        User $user,
        string $battleId,
        string $defense,
        int $expectedVersion,
        string $idempotencyKey,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw PveBattleException::notEligible('Idempotency key is required.');
        }

        $defenseType = DefenseType::tryFrom(mb_strtolower(trim($defense)))
            ?? throw PveBattleException::notEligible('Invalid defense action.');

        $payload = [
            'defense' => $defenseType->value,
            'expected_version' => $expectedVersion,
        ];

        return $this->db->transaction(function () use ($user, $battleId, $defenseType, $expectedVersion, $idempotencyKey, $payload): array {
            /** @var PveBattle|null $battle */
            $battle = PveBattle::query()->whereKey($battleId)->lockForUpdate()->first();
            if ($battle === null || (int) $battle->user_id !== (int) $user->id) {
                throw PveBattleException::notFound();
            }

            $existing = BattleAction::query()
                ->where('battle_id', $battle->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                if ((array) $existing->input_snapshot !== $payload) {
                    throw PveBattleException::idempotencyConflict();
                }
                $snap = (array) $existing->result_snapshot;

                return [
                    'battle' => $battle->fresh(['combatants.moves']),
                    'events' => (array) ($snap['events'] ?? []),
                    'reward' => $snap['reward'] ?? null,
                ];
            }

            if ($battle->isFinished() || $battle->phase !== BattlePhase::AwaitingPlayerDefense->value) {
                throw PveBattleException::phaseConflict();
            }
            if ((int) $battle->version !== $expectedVersion) {
                throw PveBattleException::versionConflict();
            }

            $state = $this->mapper->toDomain($battle);
            $cost = $state->rules->defenseStaminaCost($defenseType);
            if ($state->player->staminaCurrent < $cost) {
                throw PveBattleException::insufficientStamina();
            }

            $rng = SeededRandomSource::fromSeed(
                (int) sprintf('%u', crc32((string) $battle->rng_seed)),
                (int) $battle->rng_cursor,
            );
            $chart = $this->rules->chart($state->rules->rulesVersion);

            try {
                $transition = $this->engine->resolveCommittedAttack($state, $defenseType, $rng, $chart);
            } catch (DomainException $e) {
                $message = $e->getMessage();
                if (str_contains(mb_strtolower($message), 'stamina')) {
                    throw PveBattleException::insufficientStamina();
                }
                throw PveBattleException::phaseConflict($message);
            }

            $transition = new BattleTransition($transition->nextState, $transition->events, $rng->cursor());
            $this->mapper->applyTransition($battle, $transition);
            $battle->refresh();

            $reward = null;
            if ($battle->isFinished()) {
                $reward = $this->complete->completeIfNeeded($user, $battle);
                $battle->refresh();
            }

            $events = array_map(static fn ($e) => $e->toArray(), $transition->events);
            BattleAction::query()->create([
                'id' => (string) Str::uuid(),
                'battle_id' => $battle->id,
                'actor' => 'player',
                'action_type' => 'defend',
                'idempotency_key' => $idempotencyKey,
                'expected_version' => $expectedVersion,
                'resolved_version' => (int) $battle->version,
                'input_snapshot' => $payload,
                'result_snapshot' => [
                    'events' => $events,
                    'reward' => $reward,
                    'version' => (int) $battle->version,
                ],
            ]);

            return [
                'battle' => $battle->fresh(['combatants.moves']),
                'events' => $events,
                'reward' => $reward,
            ];
        });
    }
}
