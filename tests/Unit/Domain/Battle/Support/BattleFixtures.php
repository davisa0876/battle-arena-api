<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle\Support;

use App\Domain\Battle\BattleRules;
use App\Domain\Battle\BattleState;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\MoveSnapshot;

final class BattleFixtures
{
    /**
     * Rules with RANDOM fixed to 1 so damage is exact.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function rules(array $overrides = []): BattleRules
    {
        return BattleRules::fromArray(array_merge([
            'random_damage_min' => 1.0,
            'random_damage_max' => 1.0,
        ], $overrides));
    }

    public static function move(
        string $slug = 'ember',
        int $power = 40,
        int $accuracy = 100,
        string $type = 'fire',
        string $class = 'special',
        int $pp = 25,
        int $cost = 15,
    ): MoveSnapshot {
        return new MoveSnapshot(
            id: 10,
            slug: $slug,
            name: ucfirst($slug),
            power: $power,
            accuracy: $accuracy,
            ppCurrent: $pp,
            ppMax: max(25, $pp),
            staminaCost: $cost,
            damageClass: $class,
            typeSlug: $type,
        );
    }

    /**
     * @param  list<string>  $types
     * @param  list<MoveSnapshot>|null  $moves
     * @param  array<string, int>  $stats
     */
    public static function combatant(
        CombatSide $side,
        array $types = ['normal'],
        int $level = 50,
        int $hp = 200,
        int $stamina = 100,
        array $stats = [],
        ?array $moves = null,
    ): CombatantSnapshot {
        return new CombatantSnapshot(
            side: $side,
            name: ucfirst($side->value),
            slug: $side->value.'-mon',
            level: $level,
            types: $types,
            hpCurrent: $hp,
            hpMax: 200,
            staminaCurrent: $stamina,
            staminaMax: 100,
            stats: array_merge([
                'attack' => 100,
                'defense' => 100,
                'special_attack' => 100,
                'special_defense' => 100,
                'speed' => 100,
            ], $stats),
            moves: $moves ?? [self::move()],
        );
    }

    public static function state(
        ?CombatantSnapshot $player = null,
        ?CombatantSnapshot $opponent = null,
        ?BattleRules $rules = null,
    ): BattleState {
        return BattleState::start(
            'battle-1',
            $rules ?? self::rules(),
            $player ?? self::combatant(CombatSide::Player, ['fire']),
            $opponent ?? self::combatant(CombatSide::Opponent),
        );
    }
}
