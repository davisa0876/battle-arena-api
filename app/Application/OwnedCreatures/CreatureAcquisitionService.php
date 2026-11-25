<?php

namespace App\Application\OwnedCreatures;

use App\Application\OwnedCreatures\Exceptions\CreatureSelectionException;
use App\Application\OwnedCreatures\Exceptions\StarterSelectionException;
use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Progression\TrainerProgressionService;
use App\Domain\Selection\CreatureSelectionPolicy;
use App\Domain\Selection\Models\CreatureSelectionAcquisition;
use App\Domain\Selection\SelectionEntitlementService;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Acquires catalog creatures into a trainer's collection, consuming stage entitlements.
 */
class CreatureAcquisitionService
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Config $config,
        private readonly CreatureSelectionPolicy $selectionPolicy,
        private readonly SelectionEntitlementService $entitlements,
        private readonly TrainerProgressionService $progression,
        private readonly OwnedCreatureService $ownedCreatures,
    ) {}

    /**
     * First-time starter pick — must be an allowed starter slug and consumes stage-1.
     *
     * @throws StarterSelectionException
     * @throws CreatureSelectionException
     */
    public function selectStarter(User $user, string $slug): OwnedCreature
    {
        $slug = mb_strtolower(trim($slug));

        if (! in_array($slug, (array) $this->config->get('game.starters.creature_slugs', []), true)) {
            throw StarterSelectionException::notAllowed();
        }

        return $this->acquire($user, $slug, 'starter', 'starter:'.$user->id.':'.$slug, requireEmptyCollection: true);
    }

    /**
     * Direct catalog acquisition for an eligible evolution stage.
     *
     * @throws CreatureSelectionException
     */
    public function acquireFromCatalog(User $user, string $slug, string $idempotencyKey): OwnedCreature
    {
        $slug = mb_strtolower(trim($slug));
        $key = trim($idempotencyKey);

        if ($key === '') {
            throw CreatureSelectionException::unavailable();
        }

        return $this->acquire($user, $slug, 'catalog_selection', $key, requireEmptyCollection: false);
    }

    /**
     * @throws StarterSelectionException
     * @throws CreatureSelectionException
     */
    private function acquire(
        User $user,
        string $slug,
        string $source,
        string $idempotencyKey,
        bool $requireEmptyCollection,
    ): OwnedCreature {
        try {
            $owned = $this->db->transaction(function () use ($user, $slug, $source, $idempotencyKey, $requireEmptyCollection): OwnedCreature {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                // Starter may be chosen exactly once — check before idempotent replays.
                if ($requireEmptyCollection && OwnedCreature::query()->where('user_id', $user->id)->exists()) {
                    throw StarterSelectionException::alreadySelected();
                }

                $existingAcquisition = CreatureSelectionAcquisition::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existingAcquisition !== null) {
                    if ((int) $existingAcquisition->user_id !== (int) $user->id) {
                        throw CreatureSelectionException::duplicateRequest();
                    }

                    $existing = $this->ownedCreatures->findById((int) $existingAcquisition->owned_creature_id);
                    if ($existing !== null) {
                        return $existing;
                    }

                    throw CreatureSelectionException::duplicateRequest();
                }

                // Starters must be catalog base forms. Catalog acquire allows stage-eligible default forms.
                $creatureQuery = Creature::query()->where('slug', $slug)->where('is_default_form', true);
                if ($source === 'starter') {
                    $creatureQuery->whereNull('evolves_from_id');
                }

                $creature = $creatureQuery->first();

                if ($creature === null) {
                    if ($source === 'starter') {
                        throw StarterSelectionException::unavailable();
                    }

                    throw CreatureSelectionException::unavailable();
                }

                $stage = max(1, (int) $creature->evolution_stage);

                if ($stage >= 3 && ! $this->selectionPolicy->stage3Unlocked()) {
                    throw CreatureSelectionException::stageLocked($stage);
                }

                if (! $this->selectionPolicy->canSelectStage(
                    $this->trainerLevel($user),
                    $stage,
                )) {
                    throw CreatureSelectionException::stageLocked($stage);
                }

                if ($this->entitlements->remainingForStage($user, $stage, $user->progress) <= 0) {
                    throw CreatureSelectionException::noAllowance($stage);
                }

                if (OwnedCreature::query()->where('user_id', $user->id)->where('creature_id', $creature->id)->exists()) {
                    throw CreatureSelectionException::alreadyOwned();
                }

                $makeActive = ! OwnedCreature::query()->where('user_id', $user->id)->where('is_active', true)->exists();

                $owned = OwnedCreature::query()->create([
                    'user_id' => $user->id,
                    'creature_id' => $creature->id,
                    'level' => 1,
                    'total_xp' => 0,
                    'is_active' => $makeActive,
                    'acquired_at' => now(),
                ]);

                CreatureSelectionAcquisition::query()->create([
                    'user_id' => $user->id,
                    'owned_creature_id' => $owned->id,
                    'creature_id' => $creature->id,
                    'original_evolution_stage' => $stage,
                    'source' => $source,
                    'trainer_level_at_acquisition' => $this->trainerLevel($user),
                    'idempotency_key' => $idempotencyKey,
                ]);

                return $owned;
            });
        } catch (UniqueConstraintViolationException) {
            if ($requireEmptyCollection) {
                throw StarterSelectionException::alreadySelected();
            }

            // Concurrent acquire of same creature or same idempotency key.
            $byKey = CreatureSelectionAcquisition::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($byKey !== null && (int) $byKey->user_id === (int) $user->id) {
                $existing = $this->ownedCreatures->findById((int) $byKey->owned_creature_id);
                if ($existing !== null) {
                    return $existing;
                }
            }

            throw CreatureSelectionException::alreadyOwned();
        }

        return $this->ownedCreatures->findById($owned->id) ?? $owned;
    }

    private function trainerLevel(User $user): int
    {
        $user->loadMissing('progress');

        if ($user->progress === null) {
            return 1;
        }

        return $this->progression->levelForTotalXp((int) $user->progress->total_xp);
    }
}
