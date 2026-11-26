<?php

declare(strict_types=1);

namespace App\Domain\Battle\Pvp;

use App\Domain\Battle\MoveSnapshot;
use InvalidArgumentException;

/**
 * Immutable committed attack. Resources are NOT spent until defense resolves.
 */
final readonly class PvpAttackCommitment
{
    public function __construct(
        public int $attackerUserId,
        public ?int $moveSlot,
        public MoveSnapshot $move,
    ) {
        if ($this->attackerUserId < 1) {
            throw new InvalidArgumentException('Attacker user id is required.');
        }
        if ($this->moveSlot === null && ! $this->move->isBasicStrike) {
            throw new InvalidArgumentException('Null move slot requires basic_strike.');
        }
        if ($this->moveSlot !== null && $this->move->isBasicStrike) {
            throw new InvalidArgumentException('basic_strike cannot use a move slot.');
        }
    }

    /**
     * @return array{attacker_user_id: int, move_slot: int|null, move: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'attacker_user_id' => $this->attackerUserId,
            'move_slot' => $this->moveSlot,
            'move' => $this->move->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            attackerUserId: (int) $data['attacker_user_id'],
            moveSlot: array_key_exists('move_slot', $data) && $data['move_slot'] !== null
                ? (int) $data['move_slot']
                : null,
            move: MoveSnapshot::fromArray((array) $data['move']),
        );
    }
}
