<?php

declare(strict_types=1);

namespace App\Application\Battles\DTO;

/**
 * Provider-agnostic move snapshot used only during combat catalog import.
 */
final class ExternalMoveData
{
    public function __construct(
        public readonly string $provider,
        public readonly int $externalId,
        public readonly string $slug,
        public readonly string $name,
        public readonly ?int $power,
        public readonly ?int $accuracy,
        public readonly int $pp,
        public readonly string $damageClass,
        public readonly string $typeSlug,
        public readonly int $priority,
    ) {}

    public function isEngineEligible(): bool
    {
        return in_array($this->damageClass, ['physical', 'special'], true)
            && ($this->power ?? 0) > 0
            && $this->pp > 0;
    }
}
