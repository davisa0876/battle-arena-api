<?php

declare(strict_types=1);

namespace App\Domain\Battle;

use InvalidArgumentException;

/**
 * Frozen, versioned rules of a match (spec section 4). Built from a plain array so
 * the application layer can load it from config and snapshot it into the match.
 *
 * Expected array shape (every key optional; missing keys fall back to defaults()):
 *
 * [
 *   'rules_version' => 'pve-0.1',
 *   'stab' => 1.5,
 *   'stamina_max' => 100,
 *   'random_damage_min' => 0.9,
 *   'random_damage_max' => 1.0,
 *   'stamina_cost' => ['base' => 3, 'per_step' => 3, 'power_step' => 10, 'min' => 10, 'max' => 35],
 *   'defense' => [
 *       'endure'  => ['stamina_cost' => 0,  'damage_multiplier' => 1.0],
 *       'block'   => ['stamina_cost' => 10, 'damage_multiplier' => 0.5],
 *       'dodge'   => ['stamina_cost' => 20, 'dodge_chance' => 0.6],
 *       'recover' => ['stamina_cost' => 0,  'stamina_recover' => 15],
 *       'heal'    => ['stamina_cost' => 25, 'hp_recover' => 20],
 *   ],
 *   'basic_strike' => ['slug' => 'basic_strike', 'name' => 'Basic Strike', 'power' => 20,
 *                      'accuracy' => 100, 'type_slug' => 'normal', 'damage_class' => 'physical',
 *                      'stamina_cost' => 0, 'priority' => 0],
 * ]
 */
final readonly class BattleRules
{
    /**
     * @param  array{base: int, per_step: int, power_step: int, min: int, max: int}  $staminaCostFormula
     * @param  array<string, int>  $defenseStaminaCosts  keyed by DefenseType value
     * @param  array<string, float>  $defenseDamageMultipliers  keyed by DefenseType value (dodge success = 0 is applied by the engine)
     * @param  array{slug: string, name: string, power: int, accuracy: int, type_slug: string, damage_class: string, stamina_cost: int, priority: int}  $basicStrike
     */
    public function __construct(
        public string $rulesVersion,
        public float $stab,
        public int $staminaMax,
        public float $randomDamageMin,
        public float $randomDamageMax,
        public array $staminaCostFormula,
        public array $defenseStaminaCosts,
        public array $defenseDamageMultipliers,
        public float $dodgeChance,
        public int $recoverStamina,
        public int $recoverHp,
        public array $basicStrike,
    ) {
        if ($stab < 1.0) {
            throw new InvalidArgumentException('STAB must be at least 1.');
        }
        if ($staminaMax < 1) {
            throw new InvalidArgumentException('stamina_max must be positive.');
        }
        if ($randomDamageMin <= 0.0 || $randomDamageMin > $randomDamageMax) {
            throw new InvalidArgumentException('Invalid random damage range.');
        }
        if ($dodgeChance < 0.0 || $dodgeChance > 1.0) {
            throw new InvalidArgumentException('dodge_chance must be within [0, 1].');
        }
        if ($recoverStamina < 0) {
            throw new InvalidArgumentException('stamina_recover must not be negative.');
        }
        if ($recoverHp < 0) {
            throw new InvalidArgumentException('hp_recover must not be negative.');
        }
        foreach (DefenseType::cases() as $defense) {
            if (! isset($defenseStaminaCosts[$defense->value]) || $defenseStaminaCosts[$defense->value] < 0) {
                throw new InvalidArgumentException("Missing or invalid stamina cost for {$defense->value}.");
            }
            if (! isset($defenseDamageMultipliers[$defense->value])) {
                throw new InvalidArgumentException("Missing damage multiplier for {$defense->value}.");
            }
        }
    }

    /**
     * Spec 4 / 5 baseline (pve-0.1).
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'rules_version' => 'pve-0.1',
            'stab' => 1.5,
            'stamina_max' => 100,
            'random_damage_min' => 0.9,
            'random_damage_max' => 1.0,
            'stamina_cost' => [
                'base' => StaminaCost::DEFAULT_BASE,
                'per_step' => StaminaCost::DEFAULT_PER_STEP,
                'power_step' => StaminaCost::DEFAULT_POWER_STEP,
                'min' => StaminaCost::DEFAULT_MIN,
                'max' => StaminaCost::DEFAULT_MAX,
            ],
            'defense' => [
                'endure' => ['stamina_cost' => 0, 'damage_multiplier' => 1.0],
                'block' => ['stamina_cost' => 10, 'damage_multiplier' => 0.5],
                'dodge' => ['stamina_cost' => 20, 'dodge_chance' => 0.6],
                'recover' => ['stamina_cost' => 0, 'stamina_recover' => 15],
                'heal' => ['stamina_cost' => 25, 'hp_recover' => 20, 'damage_multiplier' => 1.0],
            ],
            'basic_strike' => [
                'slug' => 'basic_strike',
                'name' => 'Basic Strike',
                'power' => 20,
                'accuracy' => 100,
                'type_slug' => 'normal',
                'damage_class' => 'physical',
                'stamina_cost' => 0,
                'priority' => 0,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data = []): self
    {
        $defaults = self::defaults();

        /** @var array<string, mixed> $cost */
        $cost = array_merge($defaults['stamina_cost'], (array) ($data['stamina_cost'] ?? []));
        /** @var array<string, array<string, mixed>> $defense */
        $defense = $defaults['defense'];
        foreach ((array) ($data['defense'] ?? []) as $key => $override) {
            $defense[$key] = array_merge($defense[$key] ?? [], (array) $override);
        }
        /** @var array<string, mixed> $basic */
        $basic = array_merge($defaults['basic_strike'], (array) ($data['basic_strike'] ?? []));

        $costs = [];
        $multipliers = [];
        foreach (DefenseType::cases() as $type) {
            $entry = $defense[$type->value] ?? [];
            $costs[$type->value] = (int) ($entry['stamina_cost'] ?? 0);
            $multipliers[$type->value] = (float) ($entry['damage_multiplier'] ?? 1.0);
        }

        return new self(
            rulesVersion: (string) ($data['rules_version'] ?? $defaults['rules_version']),
            stab: (float) ($data['stab'] ?? $defaults['stab']),
            staminaMax: (int) ($data['stamina_max'] ?? $defaults['stamina_max']),
            randomDamageMin: (float) ($data['random_damage_min'] ?? $defaults['random_damage_min']),
            randomDamageMax: (float) ($data['random_damage_max'] ?? $defaults['random_damage_max']),
            staminaCostFormula: [
                'base' => (int) $cost['base'],
                'per_step' => (int) $cost['per_step'],
                'power_step' => (int) $cost['power_step'],
                'min' => (int) $cost['min'],
                'max' => (int) $cost['max'],
            ],
            defenseStaminaCosts: $costs,
            defenseDamageMultipliers: $multipliers,
            dodgeChance: (float) ($defense['dodge']['dodge_chance'] ?? 0.6),
            recoverStamina: (int) ($defense['recover']['stamina_recover'] ?? 15),
            recoverHp: (int) ($defense['heal']['hp_recover'] ?? 20),
            basicStrike: [
                'slug' => (string) $basic['slug'],
                'name' => (string) $basic['name'],
                'power' => (int) $basic['power'],
                'accuracy' => (int) $basic['accuracy'],
                'type_slug' => (string) $basic['type_slug'],
                'damage_class' => (string) $basic['damage_class'],
                'stamina_cost' => (int) $basic['stamina_cost'],
                'priority' => (int) ($basic['priority'] ?? 0),
            ],
        );
    }

    public function defenseStaminaCost(DefenseType $defense): int
    {
        return $this->defenseStaminaCosts[$defense->value];
    }

    /**
     * Damage multiplier of a defense when the attack connects (dodge success is handled separately).
     */
    public function defenseDamageMultiplier(DefenseType $defense): float
    {
        return $this->defenseDamageMultipliers[$defense->value];
    }

    /**
     * Fallback move: zero PP (ppMax = ppCurrent = 0 is never spent), zero stamina by default.
     */
    public function basicStrikeMove(): MoveSnapshot
    {
        $b = $this->basicStrike;

        return new MoveSnapshot(
            id: null,
            slug: $b['slug'],
            name: $b['name'],
            power: $b['power'],
            accuracy: $b['accuracy'],
            ppCurrent: 0,
            ppMax: 0,
            staminaCost: $b['stamina_cost'],
            damageClass: $b['damage_class'],
            typeSlug: $b['type_slug'],
            priority: $b['priority'],
            isBasicStrike: true,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $defense = [];
        foreach (DefenseType::cases() as $type) {
            $defense[$type->value] = [
                'stamina_cost' => $this->defenseStaminaCosts[$type->value],
                'damage_multiplier' => $this->defenseDamageMultipliers[$type->value],
            ];
        }
        $defense['dodge']['dodge_chance'] = $this->dodgeChance;
        $defense['recover']['stamina_recover'] = $this->recoverStamina;
        $defense['heal']['hp_recover'] = $this->recoverHp;

        return [
            'rules_version' => $this->rulesVersion,
            'stab' => $this->stab,
            'stamina_max' => $this->staminaMax,
            'random_damage_min' => $this->randomDamageMin,
            'random_damage_max' => $this->randomDamageMax,
            'stamina_cost' => $this->staminaCostFormula,
            'defense' => $defense,
            'basic_strike' => $this->basicStrike,
        ];
    }
}
