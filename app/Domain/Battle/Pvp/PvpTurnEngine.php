<?php

declare(strict_types=1);

namespace App\Domain\Battle\Pvp;

use App\Domain\Battle\BattleEvent;
use App\Domain\Battle\BattleEventType;
use App\Domain\Battle\CombatExchangeCalculator;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\Random\RandomSource;
use App\Domain\Battle\ResourceCalculator;
use App\Domain\Battle\TypeEffectivenessChart;
use DomainException;

/**
 * Two-human PvP turn orchestrator. Composes {@see CombatExchangeCalculator}.
 */
final class PvpTurnEngine
{
    public function __construct(
        private readonly CombatExchangeCalculator $exchange = new CombatExchangeCalculator,
    ) {}

    /**
     * Commit an immutable attack. No PP/stamina spent yet.
     *
     * @param  int|null  $moveSlot  null => basic_strike
     */
    public function commitAttack(
        PvpBattleState $state,
        int $actorUserId,
        ?int $moveSlot,
        MoveSnapshot $move,
        int $rngCursor,
    ): PvpTurnTransition {
        $this->assertPhase($state, PvpBattlePhase::AwaitingAttack);
        if ($actorUserId !== $state->currentAttackerUserId) {
            throw new DomainException('Only the current attacker may commit a move.');
        }
        if ($state->pendingAttack !== null) {
            throw new DomainException('An attack is already committed.');
        }

        $attacker = $state->combatantForUser($actorUserId);
        $this->exchange->assertCanAttack($attacker, $moveSlot, $move);

        $sequence = $state->lastEventSequence;
        $events = [
            new BattleEvent(++$sequence, BattleEventType::AttackStarted, $state->sideForUser($actorUserId), $state->sideForUser($state->currentDefenderUserId), [
                'move_slot' => $moveSlot,
                'move_slug' => $move->slug,
                'move_name' => $move->name,
                'type_slug' => $move->typeSlug,
                'damage_class' => $move->damageClass,
                'is_basic_strike' => $move->isBasicStrike,
                'committed' => true,
                'turn' => $state->turnNumber,
            ]),
        ];

        $commitment = new PvpAttackCommitment($actorUserId, $moveSlot, $move);
        $next = $state->copy(
            phase: PvpBattlePhase::AwaitingDefense,
            version: $state->version + 1,
            pendingAttack: $commitment,
            lastEventSequence: $sequence,
        );

        return new PvpTurnTransition($next, $events, $rngCursor);
    }

    /**
     * Recover-skip: restore stamina and switch attacker immediately (no pending defense).
     */
    public function recoverSkip(PvpBattleState $state, int $actorUserId, int $rngCursor): PvpTurnTransition
    {
        $this->assertPhase($state, PvpBattlePhase::AwaitingAttack);
        if ($actorUserId !== $state->currentAttackerUserId) {
            throw new DomainException('Only the current attacker may recover-skip.');
        }

        $attacker = $state->combatantForUser($actorUserId);
        if (! $attacker->canRecoverSkipAttack()) {
            throw new DomainException('Recover skip is only available when moves have PP but insufficient stamina.');
        }

        $side = $state->sideForUser($actorUserId);
        $events = [];
        $sequence = $state->lastEventSequence;
        $emit = function (BattleEventType $type, ?CombatSide $actor, ?CombatSide $target, array $payload = []) use (&$events, &$sequence): void {
            $events[] = new BattleEvent(++$sequence, $type, $actor, $target, $payload);
        };

        $emit(BattleEventType::AttackSkipped, $side, null, [
            'reason' => 'recover_stamina',
            'turn' => $state->turnNumber,
        ]);

        $before = $attacker->staminaCurrent;
        $after = ResourceCalculator::applyRecover($before, $state->rules->recoverStamina, $attacker->staminaMax);
        $attacker = $attacker->withStamina($after);

        $emit(BattleEventType::StaminaRecovered, $side, $side, [
            'amount' => $after - $before,
            'before' => $before,
            'after' => $after,
            'reason' => 'attack_recover',
        ]);
        if ($before !== $after) {
            $emit(BattleEventType::StaminaChanged, $side, $side, [
                'reason' => 'attack_recover',
                'before' => $before,
                'after' => $after,
            ]);
        }

        $host = $actorUserId === $state->hostUserId ? $attacker : $state->host;
        $guest = $actorUserId === $state->guestUserId ? $attacker : $state->guest;

        $nextAttacker = $state->currentDefenderUserId;
        $nextDefender = $state->currentAttackerUserId;

        $next = $state->copy(
            phase: PvpBattlePhase::AwaitingAttack,
            turnNumber: $state->turnNumber + 1,
            version: $state->version + 1,
            host: $host,
            guest: $guest,
            currentAttackerUserId: $nextAttacker,
            currentDefenderUserId: $nextDefender,
            clearPending: true,
            lastEventSequence: $sequence,
        );

        return new PvpTurnTransition($next, $events, $rngCursor);
    }

    /**
     * Resolve committed attack with defender reaction.
     */
    public function resolveDefense(
        PvpBattleState $state,
        int $actorUserId,
        DefenseType $defense,
        RandomSource $random,
        TypeEffectivenessChart $chart,
    ): PvpTurnTransition {
        $this->assertPhase($state, PvpBattlePhase::AwaitingDefense);
        if ($actorUserId !== $state->currentDefenderUserId) {
            throw new DomainException('Only the current defender may react.');
        }

        $pending = $state->pendingAttack
            ?? throw new DomainException('There is no committed attack to defend against.');

        $attackerUserId = $pending->attackerUserId;
        $attackerSide = $state->sideForUser($attackerUserId);
        $attacker = $state->combatantForUser($attackerUserId);
        $defender = $state->combatantForUser($actorUserId);

        $result = $this->exchange->resolve(
            attacker: $attacker,
            defender: $defender,
            attackerSide: $attackerSide,
            moveSlot: $pending->moveSlot,
            move: $pending->move,
            defenseType: $defense,
            rules: $state->rules,
            random: $random,
            chart: $chart,
            eventSequence: $state->lastEventSequence,
            round: $state->turnNumber,
            emitAttackStarted: false,
        );

        $events = $result->events;
        $sequence = $result->lastEventSequence;

        if ($attackerUserId === $state->hostUserId) {
            $host = $result->attacker;
            $guest = $result->defender;
        } else {
            $host = $result->defender;
            $guest = $result->attacker;
        }

        if ($result->defenderFainted) {
            $events[] = new BattleEvent(++$sequence, BattleEventType::BattleFinished, null, null, [
                'winner_user_id' => $attackerUserId,
                'loser_user_id' => $actorUserId,
                'end_reason' => 'combat',
                'turn' => $state->turnNumber,
            ]);

            $next = $state->copy(
                phase: PvpBattlePhase::Finished,
                version: $state->version + 1,
                host: $host,
                guest: $guest,
                clearPending: true,
                lastEventSequence: $sequence,
                winnerUserId: $attackerUserId,
                loserUserId: $actorUserId,
                withResult: true,
            );

            return new PvpTurnTransition($next, $events, $result->rngCursor);
        }

        $next = $state->copy(
            phase: PvpBattlePhase::AwaitingAttack,
            turnNumber: $state->turnNumber + 1,
            version: $state->version + 1,
            host: $host,
            guest: $guest,
            currentAttackerUserId: $actorUserId,
            currentDefenderUserId: $attackerUserId,
            clearPending: true,
            lastEventSequence: $sequence,
        );

        return new PvpTurnTransition($next, $events, $result->rngCursor);
    }

    private function assertPhase(PvpBattleState $state, PvpBattlePhase $expected): void
    {
        if ($state->phase !== $expected) {
            throw new DomainException(sprintf(
                'Invalid PvP phase: expected %s, got %s.',
                $expected->value,
                $state->phase->value,
            ));
        }
    }
}
