<?php

namespace App\Application\Creatures\Services;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Resolves which catalog creature IDs the current trainer already owns.
 * Result is cached on the request so Resource collections do not N+1.
 */
final class OwnedCreatureLookup
{
    public const REQUEST_ATTRIBUTE = 'owned_creature_ids';

    /**
     * @return array<int, true> keyed by creature_id
     */
    public function forRequest(Request $request): array
    {
        if ($request->attributes->has(self::REQUEST_ATTRIBUTE)) {
            /** @var array<int, true> $cached */
            $cached = $request->attributes->get(self::REQUEST_ATTRIBUTE);

            return $cached;
        }

        $user = $request->user('sanctum');
        $map = $user instanceof User ? $this->idsForUser($user) : [];
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $map);

        return $map;
    }

    /**
     * @return array<int, true>
     */
    public function idsForUser(User $user): array
    {
        $ids = OwnedCreature::query()
            ->where('user_id', $user->id)
            ->pluck('creature_id')
            ->all();

        $map = [];
        foreach ($ids as $id) {
            $map[(int) $id] = true;
        }

        return $map;
    }

    public function isOwned(Request $request, int $creatureId): bool
    {
        return isset($this->forRequest($request)[$creatureId]);
    }
}
