<?php

namespace App\Application\Battles;

use App\Domain\Battle\BattleRules;
use App\Domain\Battle\Models\TypeEffectiveness;
use App\Domain\Battle\TypeEffectivenessChart;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Translates the `game.pve` configuration into the pure domain {@see BattleRules} and builds the
 * persisted type chart. Matches freeze these at start; they are never re-read for a running match.
 */
class PveRulesProvider
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function rulesVersion(): string
    {
        return (string) $this->config->get('game.pve.rules_version', 'pve-0.1');
    }

    public function versionGroup(): string
    {
        return (string) $this->config->get('game.pve.version_group', 'scarlet-violet');
    }

    public function maxMoves(): int
    {
        return max(1, (int) $this->config->get('game.pve.max_moves', 4));
    }

    public function maxConcurrentActive(): int
    {
        return max(1, (int) $this->config->get('game.pve.max_concurrent_active', 1));
    }

    /**
     * @return list<string>
     */
    public function difficulties(): array
    {
        return array_values(array_map('strval', (array) $this->config->get('game.pve.difficulties', ['easy', 'normal'])));
    }

    public function defaultDifficulty(): string
    {
        return (string) $this->config->get('game.pve.default_difficulty', 'normal');
    }

    /**
     * @return list<string>
     */
    public function opponentCreatureSlugs(): array
    {
        return array_values(array_map('strval', (array) $this->config->get('game.pve.opponent_creature_slugs', [])));
    }

    public function rules(): BattleRules
    {
        return BattleRules::fromArray($this->rulesArray());
    }

    /**
     * Rebuilds the rules frozen into a battle row.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function rulesFromSnapshot(array $snapshot): BattleRules
    {
        return BattleRules::fromArray($snapshot);
    }

    /**
     * @return array<string, mixed>
     */
    public function rulesArray(): array
    {
        /** @var array<string, mixed> $pve */
        $pve = (array) $this->config->get('game.pve', []);
        $cost = (array) ($pve['stamina_cost'] ?? []);
        $defense = (array) ($pve['defense'] ?? []);

        $data = [
            'rules_version' => $this->rulesVersion(),
            'stab' => $pve['stab'] ?? 1.5,
            'stamina_max' => $pve['stamina_max'] ?? 100,
            'random_damage_min' => $pve['random_damage_min'] ?? 1.0,
            'random_damage_max' => $pve['random_damage_max'] ?? 1.0,
            'stamina_cost' => array_filter([
                'base' => $cost['base'] ?? null,
                'per_step' => $cost['per_ten_power'] ?? null,
                'power_step' => 10,
                'min' => $cost['min'] ?? null,
                'max' => $cost['max'] ?? null,
            ], static fn ($value): bool => $value !== null),
            'defense' => [
                'endure' => $this->defenseEntry($defense, 'endure'),
                'block' => $this->defenseEntry($defense, 'block'),
                'dodge' => $this->defenseEntry($defense, 'dodge') + [
                    'dodge_chance' => $defense['dodge']['success_chance'] ?? null,
                ],
                'recover' => $this->defenseEntry($defense, 'recover') + [
                    'stamina_recover' => $defense['recover']['stamina_restore'] ?? null,
                ],
            ],
        ];

        if (isset($pve['basic_strike'])) {
            $data['basic_strike'] = (array) $pve['basic_strike'];
        }

        // Drop null leaves so BattleRules::defaults() applies.
        $data['defense'] = array_map(
            static fn (array $entry): array => array_filter($entry, static fn ($value): bool => $value !== null),
            $data['defense'],
        );

        return $data;
    }

    /**
     * @param  array<string, mixed>  $defense
     * @return array<string, mixed>
     */
    private function defenseEntry(array $defense, string $key): array
    {
        $entry = (array) ($defense[$key] ?? []);

        return array_filter([
            'stamina_cost' => $entry['stamina_cost'] ?? null,
            'damage_multiplier' => $entry['damage_multiplier'] ?? null,
        ], static fn ($value): bool => $value !== null);
    }

    public function chart(?string $ruleset = null): TypeEffectivenessChart
    {
        $matrix = [];

        TypeEffectiveness::query()
            ->where('ruleset', $ruleset ?? $this->rulesVersion())
            ->get(['attacking_type_slug', 'defending_type_slug', 'multiplier'])
            ->each(function (TypeEffectiveness $row) use (&$matrix): void {
                $matrix[$row->attacking_type_slug][$row->defending_type_slug] = (float) $row->multiplier;
            });

        return new TypeEffectivenessChart($matrix);
    }
}
