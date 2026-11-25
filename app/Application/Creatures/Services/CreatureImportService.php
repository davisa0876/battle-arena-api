<?php

namespace App\Application\Creatures\Services;

use App\Application\Creatures\Contracts\CreatureCatalogProvider;
use App\Application\Creatures\DTOs\ExternalCreatureData;
use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Application\Creatures\Exceptions\IncompleteCreatureDataException;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CreatureImportService
{
    /**
     * Deterministic starter catalog (~20 Gen I varieties).
     *
     * @var list<int>
     */
    public const DEFAULT_EXTERNAL_IDS = [
        1, 2, 3, 4, 5, 6, 7, 8, 9, 25,
        39, 52, 54, 66, 74, 92, 129, 133, 143, 147,
    ];

    public function __construct(
        private readonly CreatureCatalogProvider $provider,
    ) {}

    /**
     * @param  list<int>|null  $externalIds
     * @return array{imported: int, updated: int, skipped: int, failed: int, errors: list<string>}
     */
    public function import(?array $externalIds = null): array
    {
        $ids = $externalIds ?? self::DEFAULT_EXTERNAL_IDS;
        $ids = array_values(array_unique(array_map('intval', $ids)));

        try {
            $payloads = $this->provider->fetchByExternalIds($ids);
        } catch (CreatureProviderException $exception) {
            $this->logProviderFailure($exception);
            throw $exception;
        }

        $summary = $this->persistPayloads($ids, $payloads, 'externalId');
        $this->resolveEvolutionLinks();

        return $summary;
    }

    /**
     * @param  list<int>  $speciesExternalIds
     * @return array{imported: int, updated: int, skipped: int, failed: int, errors: list<string>}
     */
    public function importSpeciesIds(array $speciesExternalIds, bool $resolveEvolutions = true): array
    {
        $ids = array_values(array_unique(array_map('intval', $speciesExternalIds)));

        try {
            $payloads = $this->provider->fetchBySpeciesExternalIds($ids);
        } catch (CreatureProviderException $exception) {
            $this->logProviderFailure($exception);
            throw $exception;
        }

        $summary = $this->persistPayloads($ids, $payloads, 'speciesExternalId');

        if ($resolveEvolutions) {
            $this->resolveEvolutionLinks();
        }

        return $summary;
    }

    /**
     * @return list<int>
     */
    public function listNationalDexSpeciesIds(): array
    {
        return $this->provider->listNationalDexSpeciesIds();
    }

    public function resolveEvolutionLinks(): void
    {
        $creatures = Creature::query()
            ->where('provider', $this->provider->providerName())
            ->whereNotNull('species_external_id')
            ->get(['id', 'species_external_id', 'evolves_from_species_external_id']);

        $bySpeciesId = [];
        foreach ($creatures as $creature) {
            $bySpeciesId[(int) $creature->species_external_id] = $creature;
        }

        foreach ($creatures as $creature) {
            $fromSpeciesId = $creature->evolves_from_species_external_id;
            $parent = $fromSpeciesId !== null
                ? ($bySpeciesId[(int) $fromSpeciesId] ?? null)
                : null;

            $stage = 1;
            if ($parent !== null) {
                $visited = [];
                $cursor = $parent;
                $depth = 1;
                while ($cursor !== null && $depth < 10) {
                    if (isset($visited[$cursor->id])) {
                        break;
                    }
                    $visited[$cursor->id] = true;
                    $depth++;
                    $nextSpecies = $cursor->evolves_from_species_external_id;
                    $cursor = $nextSpecies !== null
                        ? ($bySpeciesId[(int) $nextSpecies] ?? null)
                        : null;
                }
                $stage = $depth;
            }

            Creature::query()->whereKey($creature->id)->update([
                'evolves_from_id' => $parent?->id,
                'evolution_stage' => $stage,
            ]);
        }
    }

    /**
     * @param  list<int>  $requestedIds
     * @param  list<ExternalCreatureData>  $payloads
     * @return array{imported: int, updated: int, skipped: int, failed: int, errors: list<string>}
     */
    private function persistPayloads(array $requestedIds, array $payloads, string $keyedBy): array
    {
        $summary = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        $byKey = [];
        foreach ($payloads as $payload) {
            $key = $keyedBy === 'speciesExternalId'
                ? $payload->speciesExternalId
                : $payload->externalId;
            if ($key === null) {
                continue;
            }
            $byKey[$key] = $payload;
        }

        foreach ($requestedIds as $requestedId) {
            $payload = $byKey[$requestedId] ?? null;

            if ($payload === null) {
                $summary['failed']++;
                $summary['errors'][] = "Missing provider payload for external id {$requestedId}.";
                continue;
            }

            try {
                $result = $this->persist($payload);
                $summary[$result]++;
            } catch (IncompleteCreatureDataException $exception) {
                $summary['skipped']++;
                $summary['errors'][] = $exception->getMessage();
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = "Failed to persist external id {$requestedId}: {$exception->getMessage()}";
                Log::error('Creature import persist failure.', [
                    'external_id' => $requestedId,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return 'imported'|'updated'|'skipped'
     */
    private function persist(ExternalCreatureData $payload): string
    {
        if (! $payload->isComplete()) {
            throw new IncompleteCreatureDataException(
                "Incomplete provider data for {$payload->provider}:{$payload->externalId}; existing catalog left unchanged."
            );
        }

        return DB::transaction(function () use ($payload) {
            $existing = Creature::query()
                ->where('provider', $payload->provider)
                ->where('external_id', $payload->externalId)
                ->lockForUpdate()
                ->first();

            $attributes = [
                'provider' => $payload->provider,
                'external_id' => $payload->externalId,
                'species_external_id' => $payload->speciesExternalId,
                'evolves_from_species_external_id' => $payload->evolvesFromSpeciesExternalId,
                'is_default_form' => $payload->isDefaultForm,
                'name' => $payload->name,
                'slug' => $payload->slug,
                'height_cm' => $payload->heightCm,
                'weight_g' => $payload->weightG,
                'base_hp' => $payload->baseHp,
                'base_attack' => $payload->baseAttack,
                'base_defense' => $payload->baseDefense,
                'base_special_attack' => $payload->baseSpecialAttack,
                'base_special_defense' => $payload->baseSpecialDefense,
                'base_speed' => $payload->baseSpeed,
            ];

            if ($existing === null) {
                $attributes['image_ref'] = $payload->imageRef;
                $attributes['cry_latest_url'] = $payload->cryLatestUrl;
                $attributes['cry_legacy_url'] = $payload->cryLegacyUrl;
                $attributes['evolution_stage'] = 1;

                try {
                    $creature = Creature::query()->create($attributes);
                } catch (\Illuminate\Database\UniqueConstraintViolationException) {
                    // Concurrent import won the insert race — fall through to update.
                    $existing = Creature::query()
                        ->where('provider', $payload->provider)
                        ->where('external_id', $payload->externalId)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($payload->imageRef !== null) {
                        $attributes['image_ref'] = $payload->imageRef;
                    }
                    if ($payload->cryLatestUrl !== null) {
                        $attributes['cry_latest_url'] = $payload->cryLatestUrl;
                    }
                    if ($payload->cryLegacyUrl !== null) {
                        $attributes['cry_legacy_url'] = $payload->cryLegacyUrl;
                    }

                    $existing->fill($attributes);
                    $existing->save();
                    $this->syncTypes($existing, $payload->types);

                    return 'updated';
                }

                $this->syncTypes($creature, $payload->types);

                return 'imported';
            }

            if ($payload->imageRef !== null) {
                $attributes['image_ref'] = $payload->imageRef;
            }
            if ($payload->cryLatestUrl !== null) {
                $attributes['cry_latest_url'] = $payload->cryLatestUrl;
            }
            if ($payload->cryLegacyUrl !== null) {
                $attributes['cry_legacy_url'] = $payload->cryLegacyUrl;
            }

            $existing->fill($attributes);
            $existing->save();
            $this->syncTypes($existing, $payload->types);

            return 'updated';
        });
    }

    /**
     * @param  list<array{name: string, slug: string, slot: int}>  $types
     */
    private function syncTypes(Creature $creature, array $types): void
    {
        $sync = [];

        foreach ($types as $type) {
            $creatureType = CreatureType::query()->firstOrCreate(
                ['slug' => $type['slug']],
                ['name' => $type['name']],
            );

            $sync[$creatureType->id] = ['slot' => $type['slot']];
        }

        $creature->types()->detach();
        $creature->types()->attach($sync);
    }

    private function logProviderFailure(CreatureProviderException $exception): void
    {
        Log::warning('Creature import aborted: provider failure.', [
            'provider' => $this->provider->providerName(),
            'message' => $exception->getMessage(),
        ]);
    }
}
