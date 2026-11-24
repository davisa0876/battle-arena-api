<?php

namespace App\Application\Creatures\DTOs;

/**
 * Provider-agnostic creature payload used by import orchestration.
 */
final readonly class ExternalCreatureData
{
    /**
     * @param  list<array{name: string, slug: string, slot: int}>  $types
     */
    public function __construct(
        public string $provider,
        public int $externalId,
        public string $name,
        public string $slug,
        public ?int $heightCm,
        public ?int $weightG,
        public int $baseHp,
        public int $baseAttack,
        public int $baseDefense,
        public int $baseSpecialAttack,
        public int $baseSpecialDefense,
        public int $baseSpeed,
        public array $types,
        public ?string $imageRef = null,
        public ?int $speciesExternalId = null,
        public ?int $evolvesFromSpeciesExternalId = null,
        public bool $isDefaultForm = true,
    ) {}

    public function isComplete(): bool
    {
        if ($this->name === '' || $this->slug === '') {
            return false;
        }

        if ($this->externalId < 1) {
            return false;
        }

        foreach ([
            $this->baseHp,
            $this->baseAttack,
            $this->baseDefense,
            $this->baseSpecialAttack,
            $this->baseSpecialDefense,
            $this->baseSpeed,
        ] as $stat) {
            if ($stat < 1) {
                return false;
            }
        }

        if ($this->types === []) {
            return false;
        }

        foreach ($this->types as $type) {
            if (($type['name'] ?? '') === '' || ($type['slug'] ?? '') === '' || ($type['slot'] ?? 0) < 1) {
                return false;
            }
        }

        return true;
    }
}
