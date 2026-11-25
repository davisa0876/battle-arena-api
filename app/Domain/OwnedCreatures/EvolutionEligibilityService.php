<?php

namespace App\Domain\OwnedCreatures;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Support\Collection;

/**
 * Maps catalog evolutions for owned creatures to API-ready option rows.
 *
 * Evolution requirements are not defined yet (and execution is a disabled feature),
 * so every option is reported as locked / not eligible.
 */
class EvolutionEligibilityService
{
    public const REASON_REQUIREMENTS_NOT_DEFINED = 'requirements_not_defined';

    /**
     * @param  iterable<OwnedCreature>  $ownedCreatures
     * @return array<int, list<array{creature: Creature, eligible: bool, locked: bool, reason_code: string}>>
     */
    public function optionsFor(iterable $ownedCreatures): array
    {
        $owned = Collection::make($ownedCreatures);
        $creatureIds = $owned->pluck('creature_id')->unique()->values();

        if ($creatureIds->isEmpty()) {
            return [];
        }

        $evolutionsByParent = Creature::query()
            ->whereIn('evolves_from_id', $creatureIds)
            ->with('types')
            ->orderBy('evolution_stage')
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->groupBy('evolves_from_id');

        $options = [];
        foreach ($owned as $ownedCreature) {
            $options[$ownedCreature->id] = $evolutionsByParent
                ->get($ownedCreature->creature_id, collect())
                ->map(fn (Creature $evolution): array => [
                    'creature' => $evolution,
                    'eligible' => false,
                    'locked' => true,
                    'reason_code' => self::REASON_REQUIREMENTS_NOT_DEFINED,
                ])
                ->values()
                ->all();
        }

        return $options;
    }
}
