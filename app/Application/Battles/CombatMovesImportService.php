<?php

declare(strict_types=1);

namespace App\Application\Battles;

use App\Application\Battles\DTO\ExternalLearnableMoveRef;
use App\Application\Battles\DTO\ExternalMoveData;
use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Domain\Battle\Models\CreatureLearnableMove;
use App\Domain\Battle\Models\Move;
use App\Domain\Creatures\Models\Creature;
use App\Infrastructure\External\PokeApi\PokeApiClient;
use App\Infrastructure\External\PokeApi\PokeApiCombatMapper;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Imports combat moves + per-creature learnsets from PokéAPI for the pinned version group.
 * Type chart remains fixture-seeded (Gen 9). Live battles never call PokéAPI.
 */
final class CombatMovesImportService
{
    public function __construct(
        private readonly PokeApiClient $client,
        private readonly PokeApiCombatMapper $mapper,
        private readonly PveRulesProvider $rules,
        private readonly SeedCombatDataService $fixtureSeeder,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{
     *   source: string,
     *   creatures: int,
     *   moves_upserted: int,
     *   learnable_links: int,
     *   type_rows: int,
     *   skipped_creatures: int,
     *   errors: list<string>
     * }
     */
    public function importFromPokeApi(?int $creatureLimit = null): array
    {
        $typeRows = $this->fixtureSeeder->seedTypeChartOnly();
        $versionGroup = $this->rules->versionGroup();
        $errors = [];
        $movesUpserted = 0;
        $learnableLinks = 0;
        $skipped = 0;
        $processed = 0;

        /** @var array<int, Move> $moveCache keyed by PokéAPI move id */
        $moveCache = [];

        Creature::query()
            ->where('provider', 'pokeapi')
            ->whereNotNull('external_id')
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use (
                $versionGroup,
                $creatureLimit,
                &$errors,
                &$movesUpserted,
                &$learnableLinks,
                &$skipped,
                &$processed,
                &$moveCache,
            ): bool {
                foreach ($chunk as $creature) {
                    if ($creatureLimit !== null && $creatureLimit > 0 && $processed >= $creatureLimit) {
                        return false;
                    }

                    /** @var Creature $creature */
                    $processed++;
                    try {
                        $result = $this->importCreatureLearnset($creature, $versionGroup, $moveCache);
                        $movesUpserted += $result['moves_upserted'];
                        $learnableLinks += $result['learnable_links'];
                    } catch (CreatureProviderException $e) {
                        $skipped++;
                        $errors[] = "creature:{$creature->slug}: {$e->getMessage()}";
                        Log::warning('combat.import.creature_failed', [
                            'creature_id' => $creature->id,
                            'slug' => $creature->slug,
                            'message' => $e->getMessage(),
                        ]);
                    } catch (Throwable $e) {
                        $skipped++;
                        $errors[] = "creature:{$creature->slug}: {$e->getMessage()}";
                        Log::error('combat.import.creature_unexpected', [
                            'creature_id' => $creature->id,
                            'slug' => $creature->slug,
                            'message' => $e->getMessage(),
                        ]);
                    }
                }

                return ! ($creatureLimit !== null && $creatureLimit > 0 && $processed >= $creatureLimit);
            });

        return [
            'source' => 'pokeapi',
            'creatures' => $processed,
            'moves_upserted' => $movesUpserted,
            'learnable_links' => $learnableLinks,
            'type_rows' => $typeRows,
            'skipped_creatures' => $skipped,
            'errors' => array_slice($errors, 0, 50),
        ];
    }

    /**
     * Offline / CI path: local MVP fixtures (no network).
     *
     * @return array{source: string, moves: int, type_rows: int, learnable_moves: int, creatures: int}
     */
    public function importFromFixtures(): array
    {
        $result = $this->fixtureSeeder->seed();

        return [
            'source' => 'fixtures',
            'moves' => $result['moves'],
            'type_rows' => $result['type_rows'],
            'learnable_moves' => $result['learnable_moves'],
            'creatures' => $result['creatures'],
        ];
    }

    /**
     * @param  array<int, Move>  $moveCache
     * @return array{moves_upserted: int, learnable_links: int}
     */
    private function importCreatureLearnset(Creature $creature, string $versionGroup, array &$moveCache): array
    {
        $pokemon = $this->client->getPokemon((int) $creature->external_id);
        $refs = $this->mapper->learnsetForVersionGroup($pokemon, $versionGroup);

        /** @var list<array{move: Move, ref: ExternalLearnableMoveRef}> $eligible */
        $eligible = [];
        $movesUpserted = 0;

        foreach ($refs as $ref) {
            $move = $moveCache[$ref->moveExternalId] ?? null;
            if ($move === null) {
                $dto = $this->mapper->mapMove($this->client->getMove($ref->moveExternalId));
                if (! $dto->isEngineEligible()) {
                    continue;
                }
                $move = $this->upsertMove($dto, $versionGroup);
                $moveCache[$ref->moveExternalId] = $move;
                $movesUpserted++;
            }
            $eligible[] = ['move' => $move, 'ref' => $ref];
        }

        $links = $this->db->transaction(function () use ($creature, $versionGroup, $eligible): int {
            $ensured = 0;
            $keepIds = [];

            foreach ($eligible as $row) {
                $move = $row['move'];
                $ref = $row['ref'];
                CreatureLearnableMove::query()->updateOrCreate(
                    [
                        'creature_id' => $creature->id,
                        'move_id' => $move->id,
                        'version_group' => $versionGroup,
                    ],
                    [
                        'learn_level' => $ref->learnLevel,
                        'learn_method' => $ref->learnMethod,
                    ],
                );
                $keepIds[] = (int) $move->id;
                $ensured++;
            }

            $keepIds = array_values(array_unique($keepIds));

            CreatureLearnableMove::query()
                ->where('creature_id', $creature->id)
                ->where('version_group', $versionGroup)
                ->when(
                    $keepIds !== [],
                    fn ($q) => $q->whereNotIn('move_id', $keepIds),
                    fn ($q) => $q,
                )
                ->delete();

            return $ensured;
        });

        return ['moves_upserted' => $movesUpserted, 'learnable_links' => $links];
    }

    private function upsertMove(ExternalMoveData $dto, string $versionGroup): Move
    {
        // Live import: every engine-eligible move is battle-usable (source of truth = PokéAPI learnset).
        return Move::query()->updateOrCreate(
            ['slug' => $dto->slug],
            [
                'provider' => $dto->provider,
                'external_id' => $dto->externalId,
                'name' => $dto->name,
                'power' => $dto->power,
                'accuracy' => $dto->accuracy,
                'pp' => $dto->pp,
                'damage_class' => $dto->damageClass,
                'type_slug' => $dto->typeSlug,
                'priority' => $dto->priority,
                'version_group' => $versionGroup,
                'is_mvp_supported' => true,
            ],
        );
    }
}
