<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Domain\Battle\BattleEngine;
use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\Models\BattleAction;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Battle\Random\SeededRandomSource;
use App\Infrastructure\Battles\RuleBasedOpponent;
use App\Models\User;
use DomainException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

final class SubmitPveAttackService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly BattleEngine $engine,
        private readonly PveRulesProvider $rules,
        private readonly BattleStateMapper $mapper,
        private readonly RuleBasedOpponent $ai,
        private readonly CompletePveBattleService $complete,
    ) {}

    /**
     * @return array{battle: PveBattle, events: list<array<string, mixed>>, reward: ?array}
     */
    public function submit(
        User $user,
        string $battleId,
        ?int $moveId,
        bool $basicStrike,
        bool $recoverSkip,
        int $expectedVersion,
        string $idempotencyKey,
    ): array {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw PveBattleException::notEligible('Idempotency key is required.');
        }

        $modes = (int) $basicStrike + (int) $recoverSkip + ($moveId !== null ? 1 : 0);
        if ($modes !== 1) {
            throw PveBattleException::moveNotAvailable('Provide exactly one of move_id, basic_strike, or recover.');
        }

        $payload = [
            'move_id' => $moveId,
            'basic_strike' => $basicStrike,
            'recover' => $recoverSkip,
            'expected_version' => $expectedVersion,
        ];

        return $this->db->transaction(function () use ($user, $battleId, $moveId, $basicStrike, $recoverSkip, $expectedVersion, $idempotencyKey, $payload): array {
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

            if ($battle->isFinished() || $battle->phase !== BattlePhase::AwaitingPlayerAttack->value) {
                throw PveBattleException::phaseConflict();
            }
            if ((int) $battle->version !== $expectedVersion) {
                throw PveBattleException::versionConflict();
            }

            $state = $this->mapper->toDomain($battle);
            $rng = SeededRandomSource::fromSeed(
                (int) sprintf('%u', crc32((string) $battle->rng_seed)),
                (int) $battle->rng_cursor,
            );
            $chart = $this->rules->chart($state->rules->rulesVersion);

            try {
                if ($recoverSkip) {
                    if (! $state->player->canRecoverSkipAttack()) {
                        throw PveBattleException::moveNotAvailable(
                            'Recover is only available when moves have PP but insufficient stamina.',
                        );
                    }
                    $transition = $this->engine->resolvePlayerRecoverSkipAttack($state, $rng->cursor());
                } else {
                    $moveSlot = null;
                    if (! $basicStrike) {
                        if ($moveId === null) {
                            throw PveBattleException::moveNotAvailable('A move_id is required unless basic_strike or recover is used.');
                        }
                        $moveSlot = $this->slotForMoveId($state->player->moves, $moveId);
                        if ($moveSlot === null) {
                            throw PveBattleException::moveNotAvailable();
                        }
                        if (! $state->player->canUseMove($moveSlot)) {
                            throw PveBattleException::moveNotAvailable('Move has insufficient PP or stamina.');
                        }
                    } elseif ($state->player->hasUsableMove()) {
                        throw PveBattleException::moveNotAvailable('basic_strike is only available when no equipped move can be used.');
                    } elseif ($state->player->canRecoverSkipAttack()) {
                        throw PveBattleException::moveNotAvailable(
                            'Recover stamina instead of basic_strike while moves still have PP.',
                        );
                    }

                    $incoming = $basicStrike
                        ? $state->rules->basicStrikeMove()
                        : $state->player->move((int) $moveSlot);

                    $defense = $this->ai->chooseDefense(
                        $state->opponent,
                        $state->player,
                        $incoming,
                        $state->rules,
                        $chart,
                        $rng,
                        (string) $battle->difficulty,
                    );
                    $transition = $this->engine->resolvePlayerAttack($state, $moveSlot, $defense, $rng, $chart);
                }
            } catch (DomainException $e) {
                throw $this->mapDomain($e);
            }

            $next = $transition->nextState;
            if ($next->phase === BattlePhase::AwaitingPlayerDefense) {
                $intent = $this->ai->chooseAttack(
                    $next->opponent,
                    $next->player,
                    $next->rules,
                    $chart,
                    $rng,
                    (string) $battle->difficulty,
                );
                $next = $next->withPendingOpponentAttack($intent);
                $transition = new \App\Domain\Battle\BattleTransition($next, $transition->events, $rng->cursor());
            } else {
                $transition = new \App\Domain\Battle\BattleTransition($next, $transition->events, $rng->cursor());
            }

            $this->mapper->applyTransition($battle, $transition);
            $battle->refresh();

            $reward = null;
            if ($battle->isFinished()) {
                $reward = $this->complete->completeIfNeeded($user, $battle);
                $battle->refresh();
            }

            $result = [
                'battle' => $battle->fresh(['combatants.moves']),
                'events' => array_map(static fn ($e) => $e->toArray(), $transition->events),
                'reward' => $reward,
            ];

            BattleAction::query()->create([
                'id' => (string) Str::uuid(),
                'battle_id' => $battle->id,
                'actor' => 'player',
                'action_type' => $recoverSkip ? 'recover_skip' : 'attack',
                'idempotency_key' => $idempotencyKey,
                'expected_version' => $expectedVersion,
                'resolved_version' => (int) $battle->version,
                'input_snapshot' => $payload,
                'result_snapshot' => [
                    'events' => $result['events'],
                    'reward' => $reward,
                    'version' => (int) $battle->version,
                ],
            ]);

            return $result;
        });
    }

    /**
     * @param  list<\App\Domain\Battle\MoveSnapshot>  $moves
     */
    private function slotForMoveId(array $moves, int $moveId): ?int
    {
        foreach ($moves as $slot => $move) {
            if ($move->id === $moveId) {
                return $slot;
            }
        }

        return null;
    }

    private function mapDomain(DomainException $e): PveBattleException
    {
        $message = $e->getMessage();
        if (str_contains(mb_strtolower($message), 'stamina')) {
            return PveBattleException::insufficientStamina();
        }
        if (str_contains(mb_strtolower($message), 'phase')) {
            return PveBattleException::phaseConflict($message);
        }

        return PveBattleException::moveNotAvailable($message);
    }
}
