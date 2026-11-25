<?php

namespace App\Application\Battles;

use App\Domain\Battle\Models\CreatureLearnableMove;
use App\Domain\Battle\Models\Move;
use App\Domain\Battle\Models\TypeEffectiveness;
use App\Domain\Creatures\Models\Creature;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Fixture-based, idempotent bootstrap of the PvE combat catalog (no network):
 *  - upserts the MVP moves from database/data/pve_mvp_moves.json,
 *  - upserts the type chart from database/data/type_effectiveness_gen9.json for the pinned ruleset,
 *  - attaches up to four type-matching MVP moves (normal fallbacks) to opponent-pool and starter creatures.
 *
 * Live PokéAPI import is deferred (see ImportCombatMovesCommand).
 */
class SeedCombatDataService
{
    public const MOVES_FIXTURE = 'database/data/pve_mvp_moves.json';

    public const TYPES_FIXTURE = 'database/data/type_effectiveness_gen9.json';

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PveRulesProvider $rules,
    ) {}

    /**
     * @return array{moves: int, type_rows: int, learnable_moves: int, creatures: int}
     */
    public function seed(): array
    {
        return $this->db->transaction(function (): array {
            $moves = $this->seedMoves();
            $typeRows = $this->seedTypeChart();
            [$learnable, $creatures] = $this->seedLearnableMoves();

            return [
                'moves' => $moves,
                'type_rows' => $typeRows,
                'learnable_moves' => $learnable,
                'creatures' => $creatures,
            ];
        });
    }

    private function seedMoves(): int
    {
        $supported = (array) config('game.pve.mvp_move_slugs', []);
        $count = 0;

        foreach ($this->readFixture(self::MOVES_FIXTURE) as $row) {
            Move::query()->updateOrCreate(
                ['slug' => (string) $row['slug']],
                [
                    'provider' => 'pokeapi',
                    'external_id' => $row['external_id'] ?? null,
                    'name' => (string) $row['name'],
                    'power' => $row['power'] ?? null,
                    'accuracy' => $row['accuracy'] ?? null,
                    'pp' => (int) $row['pp'],
                    'damage_class' => (string) $row['damage_class'],
                    'type_slug' => (string) $row['type_slug'],
                    'priority' => (int) ($row['priority'] ?? 0),
                    'version_group' => $this->rules->versionGroup(),
                    'is_mvp_supported' => $supported === [] || in_array($row['slug'], $supported, true),
                ],
            );
            $count++;
        }

        return $count;
    }

    private function seedTypeChart(): int
    {
        $ruleset = $this->rules->rulesVersion();
        $count = 0;

        foreach ($this->readFixture(self::TYPES_FIXTURE) as $row) {
            TypeEffectiveness::query()->updateOrCreate(
                [
                    'ruleset' => $ruleset,
                    'attacking_type_slug' => (string) $row['attacking_type_slug'],
                    'defending_type_slug' => (string) $row['defending_type_slug'],
                ],
                ['multiplier' => (float) $row['multiplier']],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @return array{0: int, 1: int} [learnable rows ensured, creatures processed]
     */
    private function seedLearnableMoves(): array
    {
        $slugs = array_values(array_unique(array_merge(
            $this->rules->opponentCreatureSlugs(),
            array_map('strval', (array) config('game.starters.creature_slugs', [])),
        )));

        $mvpMoves = Move::query()
            ->where('is_mvp_supported', true)
            ->whereIn('damage_class', ['physical', 'special'])
            ->where('power', '>', 0)
            ->orderBy('id')
            ->get();

        $versionGroup = $this->rules->versionGroup();
        $limit = $this->rules->maxMoves();
        $ensured = 0;
        $creatures = 0;

        Creature::query()->whereIn('slug', $slugs)->with('types')->get()->each(
            function (Creature $creature) use ($mvpMoves, $versionGroup, $limit, &$ensured, &$creatures): void {
                $types = $creature->types->pluck('slug')->all();

                $matching = $mvpMoves->filter(fn (Move $m): bool => in_array($m->type_slug, $types, true));
                $fallback = $mvpMoves->filter(fn (Move $m): bool => $m->type_slug === 'normal');

                $picked = $matching->merge($fallback)->unique('id')->take($limit);

                foreach ($picked as $move) {
                    CreatureLearnableMove::query()->updateOrCreate(
                        ['creature_id' => $creature->id, 'move_id' => $move->id, 'version_group' => $versionGroup],
                        ['learn_level' => 1, 'learn_method' => 'level-up'],
                    );
                    $ensured++;
                }
                $creatures++;
            },
        );

        return [$ensured, $creatures];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readFixture(string $relativePath): array
    {
        $path = base_path($relativePath);
        if (! File::isFile($path)) {
            throw new RuntimeException("Missing combat fixture: {$relativePath}");
        }

        $decoded = json_decode((string) File::get($path), true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
