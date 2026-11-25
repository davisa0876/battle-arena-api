<?php

namespace App\Application\Authentication;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

class UpdatePasswordService
{
    /**
     * Changes the password and revokes every other personal access token (other devices).
     * The current token, if any, stays valid.
     */
    public function update(User $user, string $newPassword): void
    {
        $user->forceFill(['password' => $newPassword])->save();

        $current = $user->currentAccessToken();
        $tokens = $user->tokens();

        if ($current instanceof PersonalAccessToken) {
            $tokens->where('id', '!=', $current->id);
        }

        $tokens->delete();
    }
}
