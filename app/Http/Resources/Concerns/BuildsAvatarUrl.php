<?php

namespace App\Http\Resources\Concerns;

use App\Domain\Players\Models\PlayerProfile;

trait BuildsAvatarUrl
{
    /**
     * Avatars are streamed by the API so clients never need storage credentials.
     * The `v` query parameter busts caches when the avatar changes.
     */
    protected function avatarUrl(PlayerProfile $profile): ?string
    {
        if (! $profile->hasAvatar()) {
            return null;
        }

        return route('avatars.show', [
            'userId' => $profile->user_id,
            'v' => substr(sha1((string) $profile->avatar_path), 0, 10),
        ]);
    }
}
