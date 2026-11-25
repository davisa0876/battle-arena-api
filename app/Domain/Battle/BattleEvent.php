<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Immutable, ordered domain fact. Clients render descriptions from type + payload.
 */
final readonly class BattleEvent
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public int $sequence,
        public BattleEventType $type,
        public ?CombatSide $actor,
        public ?CombatSide $target,
        public array $payload = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sequence' => $this->sequence,
            'type' => $this->type->value,
            'actor' => $this->actor?->value,
            'target' => $this->target?->value,
            'payload' => $this->payload,
        ];
    }
}
