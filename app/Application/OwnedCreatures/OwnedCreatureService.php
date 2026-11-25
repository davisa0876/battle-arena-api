<?php

namespace App\Application\OwnedCreatures;

use App\Domain\OwnedCreatures\EvolutionEligibilityService;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;

class OwnedCreatureService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly EvolutionEligibilityService $evolutions,
    ) {}

    /**
     * @return Collection<int, OwnedCreature> active creature first, then oldest acquisition
     */
    public function listFor(User $user): Collection
    {
        $owned = $user->ownedCreatures()
            ->with('creature.types')
            ->orderByDesc('is_active')
            ->orderBy('acquired_at')
            ->orderBy('id')
            ->get();

        return $this->withEvolutionOptions($owned);
    }

    public function findById(int $id): ?OwnedCreature
    {
        $owned = OwnedCreature::query()->with('creature.types')->find($id);

        return $owned === null ? null : $this->withEvolutionOptions(collect([$owned]))->first();
    }

    /**
     * Makes `$owned` the trainer's only active creature. Idempotent.
     */
    public function selectActive(User $user, OwnedCreature $owned): OwnedCreature
    {
        $this->db->transaction(function () use ($user, $owned): void {
            $locked = OwnedCreature::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->get();

            $target = $locked->firstWhere('id', $owned->id);
            abort_if($target === null, 404);

            // Deactivate first so the UNIQUE(active_user_id) index never sees two active rows.
            OwnedCreature::query()
                ->where('user_id', $user->id)
                ->where('id', '!=', $target->id)
                ->where('is_active', true)
                ->update(['is_active' => false, 'active_user_id' => null]);

            if (! $target->is_active) {
                $target->is_active = true;
                $target->save();
            }
        });

        return $this->findById($owned->id) ?? $owned;
    }

    /**
     * @param  Collection<int, OwnedCreature>  $owned
     * @return Collection<int, OwnedCreature>
     */
    private function withEvolutionOptions(Collection $owned): Collection
    {
        $options = $this->evolutions->optionsFor($owned);

        return $owned->each(
            fn (OwnedCreature $creature) => $creature->setRelation('evolutionOptions', collect($options[$creature->id] ?? []))
        );
    }
}
