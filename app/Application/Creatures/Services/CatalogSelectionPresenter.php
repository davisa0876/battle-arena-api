<?php

namespace App\Application\Creatures\Services;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Progression\TrainerProgressionService;
use App\Domain\Selection\CreatureSelectionPolicy;
use App\Domain\Selection\SelectionEntitlementService;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

/**
 * Authoritative catalog selection flags for the current request.
 * Frontends must display these values and never invent eligibility.
 *
 * @phpstan-type SelectionFlags array{
 *   can_select: bool,
 *   selection_action: 'starter'|'acquire'|null,
 *   selection_blocked_reason: 'unauthenticated'|'owned'|'starter_required'|'no_allowance'|'stage_locked'|'unavailable'|null
 * }
 */
final class CatalogSelectionPresenter
{
    private const CONTEXT_ATTRIBUTE = 'catalog_selection_context';

    public function __construct(
        private readonly OwnedCreatureLookup $ownedLookup,
        private readonly SelectionEntitlementService $entitlements,
        private readonly CreatureSelectionPolicy $selectionPolicy,
        private readonly TrainerProgressionService $progression,
        private readonly Config $config,
    ) {}

    /**
     * @return SelectionFlags
     */
    public function forCreature(Request $request, Creature $creature): array
    {
        $user = $request->user('sanctum');
        if (! $user instanceof User) {
            return $this->flags(false, null, 'unauthenticated');
        }

        $context = $this->context($request, $user);
        $creatureId = (int) $creature->id;

        if (isset($context['owned'][$creatureId])) {
            return $this->flags(false, null, 'owned');
        }

        if (! (bool) $creature->is_default_form) {
            return $this->flags(false, null, 'unavailable');
        }

        $stage = max(1, (int) ($creature->evolution_stage ?? 1));

        // First pick must be an official starter (empty collection).
        if ($context['collection_empty']) {
            $slug = mb_strtolower((string) $creature->slug);
            if (! in_array($slug, $context['starter_slugs'], true)) {
                return $this->flags(false, null, 'starter_required');
            }

            if ($stage !== 1 || $creature->evolves_from_id !== null) {
                return $this->flags(false, null, 'unavailable');
            }

            return $this->flags(true, 'starter', null);
        }

        if ($stage >= 3 && ! $this->selectionPolicy->stage3Unlocked()) {
            return $this->flags(false, null, 'stage_locked');
        }

        if (! $this->selectionPolicy->canSelectStage($context['trainer_level'], $stage)) {
            return $this->flags(false, null, 'stage_locked');
        }

        $remaining = (int) ($context['remaining_by_stage'][$stage] ?? 0);
        if ($remaining <= 0) {
            return $this->flags(false, null, 'no_allowance');
        }

        return $this->flags(true, 'acquire', null);
    }

    /**
     * @return array{
     *   owned: array<int, true>,
     *   collection_empty: bool,
     *   trainer_level: int,
     *   remaining_by_stage: array<int, int>,
     *   starter_slugs: list<string>
     * }
     */
    private function context(Request $request, User $user): array
    {
        if ($request->attributes->has(self::CONTEXT_ATTRIBUTE)) {
            /** @var array{owned: array<int, true>, collection_empty: bool, trainer_level: int, remaining_by_stage: array<int, int>, starter_slugs: list<string>} $cached */
            $cached = $request->attributes->get(self::CONTEXT_ATTRIBUTE);

            return $cached;
        }

        $user->loadMissing('progress');
        $owned = $this->ownedLookup->forRequest($request);
        $level = $user->progress !== null
            ? $this->progression->levelForTotalXp((int) $user->progress->total_xp)
            : 1;

        $remaining = [];
        foreach ($this->entitlements->allowancesFor($user, $user->progress) as $row) {
            $remaining[$row['evolution_stage']] = $row['remaining'];
        }

        $context = [
            'owned' => $owned,
            'collection_empty' => OwnedCreature::query()->where('user_id', $user->id)->doesntExist(),
            'trainer_level' => $level,
            'remaining_by_stage' => $remaining,
            'starter_slugs' => array_values(array_map(
                static fn ($slug): string => mb_strtolower((string) $slug),
                (array) $this->config->get('game.starters.creature_slugs', []),
            )),
        ];

        $request->attributes->set(self::CONTEXT_ATTRIBUTE, $context);

        return $context;
    }

    /**
     * @return SelectionFlags
     */
    private function flags(bool $canSelect, ?string $action, ?string $reason): array
    {
        return [
            'can_select' => $canSelect,
            'selection_action' => $action,
            'selection_blocked_reason' => $reason,
        ];
    }
}
