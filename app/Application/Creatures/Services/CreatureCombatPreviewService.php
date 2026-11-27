<?php

declare(strict_types=1);

namespace App\Application\Creatures\Services;

use App\Application\Battles\CombatantFactory;
use App\Domain\Creatures\Models\Creature;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Catalog combat preview: full eligible attack learnset + shared defense options.
 * Defenses are global; attacks come from the creature learnset (not the 4-slot battle loadout).
 */
final class CreatureCombatPreviewService
{
    public function __construct(
        private readonly CombatantFactory $combatants,
        private readonly ConfigRepository $config,
    ) {}

    /**
     * @return array{
     *   attacks: list<array<string, mixed>>,
     *   defenses: list<array<string, mixed>>,
     *   note: string
     * }
     */
    public function forCreature(Creature $creature): array
    {
        $creature->loadMissing('types');
        $moves = $this->combatants->previewLoadoutFor($creature);

        $attacks = [];
        foreach ($moves as $move) {
            $attacks[] = [
                'id' => $move->id,
                'slug' => $move->slug,
                'name' => $move->name,
                'power' => $move->power,
                'accuracy' => $move->accuracy,
                'pp' => $move->ppMax,
                'stamina_cost' => $move->staminaCost,
                'damage_class' => $move->damageClass,
                'type_slug' => $move->typeSlug,
            ];
        }

        return [
            'attacks' => $attacks,
            'defenses' => $this->defenses(),
            'note' => 'Attacks list this creature’s eligible learnset. Defenses are shared by every combatant.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defenses(): array
    {
        $defense = (array) $this->config->get('game.pve.defense', []);
        $labels = [
            'endure' => 'Endure',
            'block' => 'Block',
            'dodge' => 'Dodge',
            'recover' => 'Recover',
            'heal' => 'Heal',
        ];

        $out = [];
        foreach (['endure', 'block', 'dodge', 'recover', 'heal'] as $slug) {
            $entry = (array) ($defense[$slug] ?? []);
            $row = [
                'slug' => $slug,
                'name' => $labels[$slug],
                'stamina_cost' => (int) ($entry['stamina_cost'] ?? 0),
                'damage_multiplier' => isset($entry['damage_multiplier']) ? (float) $entry['damage_multiplier'] : null,
            ];
            if ($slug === 'dodge' && isset($entry['success_chance'])) {
                $row['success_chance'] = (float) $entry['success_chance'];
            }
            if ($slug === 'recover' && isset($entry['stamina_restore'])) {
                $row['stamina_restore'] = (int) $entry['stamina_restore'];
            }
            if ($slug === 'heal' && isset($entry['hp_restore'])) {
                $row['hp_restore'] = (int) $entry['hp_restore'];
            }
            $out[] = $row;
        }

        return $out;
    }
}
