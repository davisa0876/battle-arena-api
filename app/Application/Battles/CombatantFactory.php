<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\EffectiveStatCalculator;
use App\Domain\Battle\Models\CreatureLearnableMove;
use App\Domain\Battle\Models\Move;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\StaminaCost;
use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;

/**
 * Builds combatant snapshots (and move loadouts) from catalog + ownership data.
 * Battle loadouts use the full eligible learnset unless `game.pve.max_moves` soft-caps.
 */
final class CombatantFactory
{
    public function __construct(
        private readonly PveRulesProvider $rules,
    ) {}

    public function fromOwned(OwnedCreature $owned, CombatSide $side): CombatantSnapshot
    {
        $owned->loadMissing(['creature.types']);
        $creature = $owned->creature
            ?? throw new \InvalidArgumentException('Owned creature is missing catalog data.');

        return $this->fromCreature($creature, (int) $owned->level, $side);
    }

    public function fromCreature(Creature $creature, int $level, CombatSide $side): CombatantSnapshot
    {
        $creature->loadMissing('types');
        $level = max(1, $level);
        $rules = $this->rules->rules();
        $formula = $rules->staminaCostFormula;

        $hp = EffectiveStatCalculator::hp((int) $creature->base_hp, $level);
        $stats = [
            'attack' => EffectiveStatCalculator::stat((int) $creature->base_attack, $level),
            'defense' => EffectiveStatCalculator::stat((int) $creature->base_defense, $level),
            'special_attack' => EffectiveStatCalculator::stat((int) $creature->base_special_attack, $level),
            'special_defense' => EffectiveStatCalculator::stat((int) $creature->base_special_defense, $level),
            'speed' => EffectiveStatCalculator::stat((int) $creature->base_speed, $level),
        ];

        $moves = $this->loadoutFor($creature, $formula, $this->rules->maxMoves());

        return new CombatantSnapshot(
            side: $side,
            name: (string) $creature->name,
            slug: (string) $creature->slug,
            level: $level,
            types: $creature->types->pluck('slug')->values()->all(),
            hpCurrent: $hp,
            hpMax: $hp,
            staminaCurrent: $rules->staminaMax,
            staminaMax: $rules->staminaMax,
            stats: $stats,
            moves: $moves,
        );
    }

    /**
     * Full eligible attack list for catalog UI (not capped by the battle 4-slot limit).
     *
     * @return list<MoveSnapshot>
     */
    public function previewLoadoutFor(Creature $creature): array
    {
        $formula = $this->rules->rules()->staminaCostFormula;

        return $this->loadoutFor($creature, $formula, null);
    }

    /**
     * @param  array{base: int, per_step: int, power_step: int, min: int, max: int}  $formula
     * @return list<MoveSnapshot>
     */
    private function loadoutFor(Creature $creature, array $formula, ?int $limit): array
    {
        $versionGroup = $this->rules->versionGroup();

        $learnable = CreatureLearnableMove::query()
            ->where('creature_id', $creature->id)
            ->where('version_group', $versionGroup)
            ->with('move')
            ->orderByRaw("case when learn_method = 'level-up' then 0 else 1 end")
            ->orderByRaw('case when learn_level is null or learn_level = 0 then 9999 else learn_level end')
            ->orderBy('id')
            ->get();

        $moves = [];
        foreach ($learnable as $row) {
            $snapshot = $this->snapshotFromMove($row->move, $formula);
            if ($snapshot === null) {
                continue;
            }
            $moves[] = $snapshot;
            if ($limit !== null && count($moves) >= $limit) {
                break;
            }
        }

        // Fall back to type-matching MVP moves so battles never open with an empty loadout.
        if ($moves === []) {
            $moves = $this->fallbackLoadout($creature, $formula, $limit);
        }

        return $moves;
    }

    /**
     * @param  array{base: int, per_step: int, power_step: int, min: int, max: int}  $formula
     * @return list<MoveSnapshot>
     */
    private function fallbackLoadout(Creature $creature, array $formula, ?int $limit): array
    {
        $types = $creature->types->pluck('slug')->all();
        $mvpMoves = Move::query()
            ->where('is_mvp_supported', true)
            ->whereIn('damage_class', ['physical', 'special'])
            ->where('power', '>', 0)
            ->orderBy('id')
            ->get();

        $matching = $mvpMoves->filter(fn (Move $m): bool => in_array($m->type_slug, $types, true));
        $fallback = $mvpMoves->filter(fn (Move $m): bool => $m->type_slug === 'normal');
        // Prefer every type-matching move; only use normals when the creature has no typed MVP moves.
        $picked = ($matching->isNotEmpty() ? $matching : $fallback)->unique('id');
        if ($limit !== null) {
            $picked = $picked->take($limit);
        }

        $moves = [];
        foreach ($picked as $move) {
            $snapshot = $this->snapshotFromMove($move, $formula);
            if ($snapshot !== null) {
                $moves[] = $snapshot;
            }
        }

        return $moves;
    }

    /**
     * @param  array{base: int, per_step: int, power_step: int, min: int, max: int}  $formula
     */
    private function snapshotFromMove(?Move $move, array $formula): ?MoveSnapshot
    {
        if ($move === null || ! $move->is_mvp_supported || ($move->power ?? 0) <= 0) {
            return null;
        }
        if (! in_array($move->damage_class, ['physical', 'special'], true)) {
            return null;
        }

        $power = (int) $move->power;

        return new MoveSnapshot(
            id: (int) $move->id,
            slug: (string) $move->slug,
            name: (string) $move->name,
            power: $power,
            accuracy: (int) ($move->accuracy ?? 100),
            ppCurrent: (int) $move->pp,
            ppMax: (int) $move->pp,
            staminaCost: StaminaCost::forPower(
                $power,
                $formula['base'],
                $formula['per_step'],
                $formula['power_step'],
                $formula['min'],
                $formula['max'],
            ),
            damageClass: (string) $move->damage_class,
            typeSlug: (string) $move->type_slug,
            priority: (int) $move->priority,
        );
    }
}
