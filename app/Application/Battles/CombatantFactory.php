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

        $moves = $this->loadoutFor($creature, $formula);

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
     * @param  array{base: int, per_step: int, power_step: int, min: int, max: int}  $formula
     * @return list<MoveSnapshot>
     */
    private function loadoutFor(Creature $creature, array $formula): array
    {
        $versionGroup = $this->rules->versionGroup();
        $limit = $this->rules->maxMoves();

        $learnable = CreatureLearnableMove::query()
            ->where('creature_id', $creature->id)
            ->where('version_group', $versionGroup)
            ->with('move')
            ->orderBy('id')
            ->get();

        $moves = [];
        foreach ($learnable as $row) {
            /** @var Move|null $move */
            $move = $row->move;
            if ($move === null || ! $move->is_mvp_supported || ($move->power ?? 0) <= 0) {
                continue;
            }
            if (! in_array($move->damage_class, ['physical', 'special'], true)) {
                continue;
            }

            $power = (int) $move->power;
            $moves[] = new MoveSnapshot(
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

            if (count($moves) >= $limit) {
                break;
            }
        }

        return $moves;
    }
}
