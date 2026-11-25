<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use InvalidArgumentException;

/**
 * The opponent's committed counterattack. It is frozen: the player's defense can never change it.
 * The full move snapshot is stored for resolution; only {@see telegraph()} is exposed to clients.
 */
final readonly class OpponentIntent
{
    public function __construct(
        public ?int $moveSlot,
        public bool $isBasicStrike,
        public MoveSnapshot $move,
    ) {
        if ($isBasicStrike && $moveSlot !== null) {
            throw new InvalidArgumentException('basic_strike intents have no move slot.');
        }
        if (! $isBasicStrike && ($moveSlot === null || $moveSlot < 0)) {
            throw new InvalidArgumentException('A move intent requires a move slot.');
        }
        if ($isBasicStrike !== $move->isBasicStrike) {
            throw new InvalidArgumentException('Intent and move basic_strike flags must match.');
        }
    }

    public static function forMove(int $moveSlot, MoveSnapshot $move): self
    {
        return new self($moveSlot, false, $move);
    }

    public static function basicStrike(MoveSnapshot $move): self
    {
        return new self(null, true, $move);
    }

    /**
     * Limited public hint: elemental type and category only (name and power stay hidden).
     *
     * @return array{type_slug: string, damage_class: string, is_basic_strike: bool}
     */
    public function telegraph(): array
    {
        return [
            'type_slug' => $this->move->typeSlug,
            'damage_class' => $this->move->damageClass,
            'is_basic_strike' => $this->isBasicStrike,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'move_slot' => $this->moveSlot,
            'is_basic_strike' => $this->isBasicStrike,
            'move' => $this->move->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            moveSlot: isset($data['move_slot']) ? (int) $data['move_slot'] : null,
            isBasicStrike: (bool) $data['is_basic_strike'],
            move: MoveSnapshot::fromArray((array) $data['move']),
        );
    }
}
