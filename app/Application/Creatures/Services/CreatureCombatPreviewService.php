<?php

declare(strict_types=1);

namespace App\Application\Creatures\Services;

use App\Application\Battles\CombatantFactory;
use App\Domain\Battle\CombatSide;
use App\Domain\Creatures\Models\Creature;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Catalog combat preview: typed attack loadout for this creature + shared defense options.
 * Defenses are global (same for every creature); attacks come from the MVP learnable set.
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
    public function forCreature(Creature $creature, int $previewLevel = 1): array
    {
        $creature->loadMissing('types');
        $snapshot = $this->combatants->fromCreature($creature, max(1, $previewLevel), CombatSide::Player);

        $attacks = [];
        foreach ($snapshot->moves as $move) {
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
            'note' => 'Attacks are this creature’s arena loadout. Defenses are shared by every combatant.',
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
        ];

        $out = [];
        foreach (['endure', 'block', 'dodge', 'recover'] as $slug) {
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
            $out[] = $row;
        }

        return $out;
    }
}
