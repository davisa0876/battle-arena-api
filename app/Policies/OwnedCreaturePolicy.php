<?php

namespace App\Policies;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;

class OwnedCreaturePolicy
{
    public function view(User $user, OwnedCreature $owned): bool
    {
        return $this->owns($user, $owned);
    }

    public function select(User $user, OwnedCreature $owned): bool
    {
        return $this->owns($user, $owned);
    }

    private function owns(User $user, OwnedCreature $owned): bool
    {
        return (int) $owned->user_id === (int) $user->id;
    }
}
