<?php

namespace App\Application\OwnedCreatures;

use App\Application\OwnedCreatures\Exceptions\StarterSelectionException;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;

/**
 * Thin wrapper: starter pick is the first stage-1 selection entitlement.
 */
class StarterSelectionService
{
    public function __construct(
        private readonly CreatureAcquisitionService $acquisitions,
    ) {}

    /**
     * @throws StarterSelectionException
     */
    public function select(User $user, string $slug): OwnedCreature
    {
        return $this->acquisitions->selectStarter($user, $slug);
    }
}
