<?php

declare(strict_types=1);

namespace App\Domain\Battle\Pvp;

use App\Domain\Battle\BattleRules;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\CombatSide;
use InvalidArgumentException;

/**
 * Immutable two-human PvP match snapshot. Combatant sides are slots only:
 * Player = host combatant, Opponent = guest combatant. Outcomes use user IDs.
 */
final readonly class PvpBattleState
{
    public function __construct(
        public string $id,
        public string $roomId,
        public PvpBattlePhase $phase,
        public int $turnNumber,
        public int $version,
        public BattleRules $rules,
        public int $hostUserId,
        public int $guestUserId,
        public CombatantSnapshot $host,
        public CombatantSnapshot $guest,
        public int $currentAttackerUserId,
        public int $currentDefenderUserId,
        public ?PvpAttackCommitment $pendingAttack = null,
        public int $lastEventSequence = 0,
        public ?int $winnerUserId = null,
        public ?int $loserUserId = null,
    ) {
        if ($host->side !== CombatSide::Player || $guest->side !== CombatSide::Opponent) {
            throw new InvalidArgumentException('Host must be Player side; guest must be Opponent side.');
        }
        if ($hostUserId === $guestUserId || $hostUserId < 1 || $guestUserId < 1) {
            throw new InvalidArgumentException('Host and guest must be distinct positive user ids.');
        }
        if ($turnNumber < 1 || $version < 1 || $lastEventSequence < 0) {
            throw new InvalidArgumentException('Invalid turn, version or event sequence.');
        }
        $ids = [$hostUserId, $guestUserId];
        if (! in_array($currentAttackerUserId, $ids, true) || ! in_array($currentDefenderUserId, $ids, true)) {
            throw new InvalidArgumentException('Attacker/defender must be battle participants.');
        }
        if ($currentAttackerUserId === $currentDefenderUserId) {
            throw new InvalidArgumentException('Attacker and defender must differ.');
        }
        if (($phase === PvpBattlePhase::Finished) !== ($winnerUserId !== null && $loserUserId !== null)) {
            throw new InvalidArgumentException('Winner/loser set if and only if finished.');
        }
        if ($pendingAttack !== null && $phase !== PvpBattlePhase::AwaitingDefense) {
            throw new InvalidArgumentException('Pending attack only valid while awaiting defense.');
        }
        if ($pendingAttack === null && $phase === PvpBattlePhase::AwaitingDefense) {
            throw new InvalidArgumentException('Awaiting defense requires a pending attack.');
        }
    }

    public function combatantForUser(int $userId): CombatantSnapshot
    {
        return match ($userId) {
            $this->hostUserId => $this->host,
            $this->guestUserId => $this->guest,
            default => throw new InvalidArgumentException('User is not a battle participant.'),
        };
    }

    public function sideForUser(int $userId): CombatSide
    {
        return match ($userId) {
            $this->hostUserId => CombatSide::Player,
            $this->guestUserId => CombatSide::Opponent,
            default => throw new InvalidArgumentException('User is not a battle participant.'),
        };
    }

    public function userIdForSide(CombatSide $side): int
    {
        return $side === CombatSide::Player ? $this->hostUserId : $this->guestUserId;
    }

    public function withCombatants(CombatantSnapshot $host, CombatantSnapshot $guest): self
    {
        return $this->copy(host: $host, guest: $guest);
    }

    public function copy(
        ?PvpBattlePhase $phase = null,
        ?int $turnNumber = null,
        ?int $version = null,
        ?CombatantSnapshot $host = null,
        ?CombatantSnapshot $guest = null,
        ?int $currentAttackerUserId = null,
        ?int $currentDefenderUserId = null,
        ?PvpAttackCommitment $pendingAttack = null,
        bool $clearPending = false,
        ?int $lastEventSequence = null,
        ?int $winnerUserId = null,
        ?int $loserUserId = null,
        bool $withResult = false,
    ): self {
        return new self(
            id: $this->id,
            roomId: $this->roomId,
            phase: $phase ?? $this->phase,
            turnNumber: $turnNumber ?? $this->turnNumber,
            version: $version ?? $this->version,
            rules: $this->rules,
            hostUserId: $this->hostUserId,
            guestUserId: $this->guestUserId,
            host: $host ?? $this->host,
            guest: $guest ?? $this->guest,
            currentAttackerUserId: $currentAttackerUserId ?? $this->currentAttackerUserId,
            currentDefenderUserId: $currentDefenderUserId ?? $this->currentDefenderUserId,
            pendingAttack: $clearPending ? null : ($pendingAttack ?? $this->pendingAttack),
            lastEventSequence: $lastEventSequence ?? $this->lastEventSequence,
            winnerUserId: $withResult ? $winnerUserId : $this->winnerUserId,
            loserUserId: $withResult ? $loserUserId : $this->loserUserId,
        );
    }

    public function isFinished(): bool
    {
        return $this->phase === PvpBattlePhase::Finished;
    }
}
