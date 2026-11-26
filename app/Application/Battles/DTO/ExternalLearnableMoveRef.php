<?php

declare(strict_types=1);

namespace App\Application\Battles\DTO;

/**
 * One learnset row extracted from a Pokémon payload for a pinned version group.
 */
final class ExternalLearnableMoveRef
{
    public function __construct(
        public readonly int $moveExternalId,
        public readonly string $moveSlug,
        public readonly ?int $learnLevel,
        public readonly ?string $learnMethod,
    ) {}
}
