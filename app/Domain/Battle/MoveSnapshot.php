<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use InvalidArgumentException;

/**
 * Immutable snapshot of a move as it exists inside a match.
 * `id` is null for the Battle Arena original `basic_strike` fallback.
 */
final readonly class MoveSnapshot
{
    public function __construct(
        public ?int $id,
        public string $slug,
        public string $name,
        public int $power,
        public int $accuracy,
        public int $ppCurrent,
        public int $ppMax,
        public int $staminaCost,
        public string $damageClass,
        public string $typeSlug,
        public int $priority = 0,
        public bool $isBasicStrike = false,
    ) {
        if ($power < 0 || $staminaCost < 0) {
            throw new InvalidArgumentException('Power and stamina cost must not be negative.');
        }
        if ($accuracy < 0 || $accuracy > 100) {
            throw new InvalidArgumentException('Accuracy must be within [0, 100].');
        }
        if ($ppMax < 0 || $ppCurrent < 0 || $ppCurrent > $ppMax) {
            throw new InvalidArgumentException('PP must be within [0, pp_max].');
        }
        if (! in_array($damageClass, ['physical', 'special'], true)) {
            throw new InvalidArgumentException('Only physical and special damage classes are supported.');
        }
    }

    public function isSpecial(): bool
    {
        return $this->damageClass === 'special';
    }

    /**
     * PP availability; basic_strike never consumes PP.
     */
    public function hasPp(): bool
    {
        return $this->isBasicStrike || $this->ppCurrent > 0;
    }

    public function withPp(int $ppCurrent): self
    {
        return new self(
            $this->id, $this->slug, $this->name, $this->power, $this->accuracy,
            max(0, min($this->ppMax, $ppCurrent)), $this->ppMax, $this->staminaCost,
            $this->damageClass, $this->typeSlug, $this->priority, $this->isBasicStrike,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'power' => $this->power,
            'accuracy' => $this->accuracy,
            'pp_current' => $this->ppCurrent,
            'pp_max' => $this->ppMax,
            'stamina_cost' => $this->staminaCost,
            'damage_class' => $this->damageClass,
            'type_slug' => $this->typeSlug,
            'priority' => $this->priority,
            'is_basic_strike' => $this->isBasicStrike,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: isset($data['id']) ? (int) $data['id'] : null,
            slug: (string) $data['slug'],
            name: (string) $data['name'],
            power: (int) $data['power'],
            accuracy: (int) $data['accuracy'],
            ppCurrent: (int) $data['pp_current'],
            ppMax: (int) $data['pp_max'],
            staminaCost: (int) $data['stamina_cost'],
            damageClass: (string) $data['damage_class'],
            typeSlug: (string) $data['type_slug'],
            priority: (int) ($data['priority'] ?? 0),
            isBasicStrike: (bool) ($data['is_basic_strike'] ?? false),
        );
    }
}
