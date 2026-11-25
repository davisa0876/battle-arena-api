<?php

namespace App\Application\Players;

use App\Domain\Players\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Database\DatabaseManager;

class UpdatePlayerProfileService
{
    private const EDITABLE = ['handle', 'display_name', 'bio', 'collection_is_public'];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated subset of handle, display_name, bio, collection_is_public
     */
    public function update(User $user, array $data): PlayerProfile
    {
        return $this->db->transaction(function () use ($user, $data): PlayerProfile {
            $profile = $user->profile()->lockForUpdate()->firstOrFail();

            $profile->fill(array_intersect_key($data, array_flip(self::EDITABLE)));
            $profile->save();

            if ($profile->wasChanged('display_name')) {
                $user->forceFill(['name' => $profile->display_name])->save();
            }

            return $profile;
        });
    }
}
