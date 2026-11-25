<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use DomainException;
use InvalidArgumentException;

/**
 * Immutable typed snapshot of a PvE match. No Eloquent, no I/O.
 *
 * `outcome` is from the player's perspective ('win' | 'loss'), matching BattleRewardPolicy.
 */
final readonly class BattleState
{
    public function __construct(
        public string $id,
        public BattlePhase $phase,
        public int $round,
        public int $version,
        public BattleRules $rules,
        public CombatantSnapshot $player,
        public CombatantSnapshot $opponent,
        public ?OpponentIntent $pendingOpponentAttack = null,
        public int $lastEventSequence = 0,
        public ?string $outcome = null,
    ) {
        if ($player->side !== CombatSide::Player || $opponent->side !== CombatSide::Opponent) {
            throw new InvalidArgumentException('Combatant sides do not match their slots.');
        }
        if ($round < 1 || $version < 0 || $lastEventSequence < 0) {
            throw new InvalidArgumentException('Invalid round, version or event sequence.');
        }
        if ($outcome !== null && ! in_array($outcome, [BattleRewardPolicy::OUTCOME_WIN, BattleRewardPolicy::OUTCOME_LOSS], true)) {
            throw new InvalidArgumentException('Outcome must be win or loss.');
        }
        if (($phase === BattlePhase::Finished) !== ($outcome !== null)) {
            throw new InvalidArgumentException('Outcome is set if and only if the battle is finished.');
        }
        if ($pendingOpponentAttack !== null && $phase !== BattlePhase::AwaitingPlayerDefense) {
            throw new InvalidArgumentException('A pending opponent attack is only valid while awaiting player defense.');
        }
    }

    /**
     * Initial state: player attacks first (spec 1).
     */
    public static function start(
        string $id,
        BattleRules $rules,
        CombatantSnapshot $player,
        CombatantSnapshot $opponent,
    ): self {
        return new self($id, BattlePhase::AwaitingPlayerAttack, 1, 1, $rules, $player, $opponent);
    }

    public function combatant(CombatSide $side): CombatantSnapshot
    {
        return $side === CombatSide::Player ? $this->player : $this->opponent;
    }

    public function isFinished(): bool
    {
        return $this->phase === BattlePhase::Finished;
    }

    public function withCombatant(CombatantSnapshot $combatant): self
    {
        return $this->copy(
            player: $combatant->side === CombatSide::Player ? $combatant : $this->player,
            opponent: $combatant->side === CombatSide::Opponent ? $combatant : $this->opponent,
        );
    }

    /**
     * Application layer attaches the AI's committed counterattack after the player's attack exchange.
     * Does not change the version (it belongs to the same persisted transition).
     */
    public function withPendingOpponentAttack(OpponentIntent $intent): self
    {
        if ($this->phase !== BattlePhase::AwaitingPlayerDefense) {
            throw new DomainException('An opponent attack can only be committed while awaiting player defense.');
        }
        if ($this->pendingOpponentAttack !== null) {
            throw new DomainException('The opponent attack is already committed and cannot be replaced.');
        }

        $opponent = $this->opponent;
        if ($intent->isBasicStrike) {
            if ($opponent->hasUsableMove()) {
                throw new DomainException('basic_strike is only available when no move can be used.');
            }
        } else {
            $slot = (int) $intent->moveSlot;
            if (! $opponent->canUseMove($slot) || $opponent->move($slot)->slug !== $intent->move->slug) {
                throw new DomainException('The committed move is not available to the opponent.');
            }
        }

        return $this->copy(pendingOpponentAttack: $intent, withPending: true);
    }

    /**
     * Internal transition helper used by the engine.
     */
    public function with(
        BattlePhase $phase,
        int $round,
        int $version,
        CombatantSnapshot $player,
        CombatantSnapshot $opponent,
        int $lastEventSequence,
        ?string $outcome,
        ?OpponentIntent $pendingOpponentAttack = null,
    ): self {
        return new self(
            $this->id, $phase, $round, $version, $this->rules, $player, $opponent,
            $pendingOpponentAttack, $lastEventSequence, $outcome,
        );
    }

    private function copy(
        ?CombatantSnapshot $player = null,
        ?CombatantSnapshot $opponent = null,
        ?OpponentIntent $pendingOpponentAttack = null,
        bool $withPending = false,
    ): self {
        return new self(
            $this->id, $this->phase, $this->round, $this->version, $this->rules,
            $player ?? $this->player, $opponent ?? $this->opponent,
            $withPending ? $pendingOpponentAttack : $this->pendingOpponentAttack,
            $this->lastEventSequence, $this->outcome,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase->value,
            'round' => $this->round,
            'version' => $this->version,
            'rules' => $this->rules->toArray(),
            'player' => $this->player->toArray(),
            'opponent' => $this->opponent->toArray(),
            'pending_opponent_attack' => $this->pendingOpponentAttack?->toArray(),
            'last_event_sequence' => $this->lastEventSequence,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (string) $data['id'],
            phase: BattlePhase::from((string) $data['phase']),
            round: (int) $data['round'],
            version: (int) $data['version'],
            rules: BattleRules::fromArray((array) $data['rules']),
            player: CombatantSnapshot::fromArray((array) $data['player']),
            opponent: CombatantSnapshot::fromArray((array) $data['opponent']),
            pendingOpponentAttack: isset($data['pending_opponent_attack'])
                ? OpponentIntent::fromArray((array) $data['pending_opponent_attack'])
                : null,
            lastEventSequence: (int) ($data['last_event_sequence'] ?? 0),
            outcome: isset($data['outcome']) ? (string) $data['outcome'] : null,
        );
    }
}
