<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Step-by-step damage breakdown (spec 5.1), useful for events and tests.
 */
final readonly class DamageResult
{
    public function __construct(
        public int $baseDamage,
        public float $stab,
        public float $typeMultiplier,
        public float $randomFactor,
        public int $modifiedDamage,
        public float $defenseMultiplier,
        /** Damage after the defense multiplier and the 1 HP minimum, before the HP clamp. */
        public int $unclampedDamage,
        /** Final damage after clamping to [0, defender current HP]. */
        public int $damage,
        public bool $isImmune,
        public bool $minimumApplied,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'base_damage' => $this->baseDamage,
            'stab' => $this->stab,
            'type_multiplier' => $this->typeMultiplier,
            'random_factor' => $this->randomFactor,
            'modified_damage' => $this->modifiedDamage,
            'defense_multiplier' => $this->defenseMultiplier,
            'damage' => $this->damage,
            'is_immune' => $this->isImmune,
            'minimum_applied' => $this->minimumApplied,
        ];
    }
}
