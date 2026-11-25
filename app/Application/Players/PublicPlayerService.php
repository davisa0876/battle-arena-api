<?php

namespace App\Application\Players;

use App\Application\OwnedCreatures\OwnedCreatureService;
use App\Domain\Players\Models\PlayerProfile;

class PublicPlayerService
{
    public function __construct(
        private readonly OwnedCreatureService $ownedCreatures,
    ) {}

    /**
     * Finds a profile by (case-insensitive) handle. The collection relation is only loaded
     * when the trainer opted in with `collection_is_public`.
     */
    public function findByHandle(string $handle): ?PlayerProfile
    {
        $profile = PlayerProfile::query()
            ->with('user.progress')
            ->where('handle', PlayerProfile::normalizeHandle($handle))
            ->first();

        if ($profile === null) {
            return null;
        }

        if ($profile->collection_is_public) {
            $profile->setRelation('collection', $this->ownedCreatures->listFor($profile->user));
        }

        return $profile;
    }
}
