<?php

namespace App\Application\Players;

use App\Models\User;

class PlayerAccountService
{
    /**
     * Loads everything AuthenticatedUserResource needs in as few queries as possible.
     */
    public function load(User $user): User
    {
        return $user->load(['profile', 'progress'])->loadExists('ownedCreatures');
    }
}
