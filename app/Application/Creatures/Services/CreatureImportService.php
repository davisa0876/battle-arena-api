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
     * Documented selection for reproducible local development and demos.
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

        $summary = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        try {
            $payloads = $this->provider->fetchByExternalIds($ids);
        } catch (CreatureProviderException $exception) {
            Log::warning('Creature import aborted: provider failure.', [
                'provider' => $this->provider->providerName(),
                'message' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $byExternalId = [];
        foreach ($payloads as $payload) {
            $byExternalId[$payload->externalId] = $payload;
        }

        foreach ($ids as $externalId) {
            $payload = $byExternalId[$externalId] ?? null;

            if ($payload === null) {
                $summary['failed']++;
                $summary['errors'][] = "Missing provider payload for external id {$externalId}.";
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
                $summary['errors'][] = "Failed to persist external id {$externalId}: {$exception->getMessage()}";
                Log::error('Creature import persist failure.', [
                    'external_id' => $externalId,
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
                ->first();

            $attributes = [
                'provider' => $payload->provider,
                'external_id' => $payload->externalId,
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
                $creature = Creature::query()->create($attributes);
                $this->syncTypes($creature, $payload->types);

                return 'imported';
            }

            // Never replace a valid stored image with null from incomplete upstream data.
            // Allow upgrades when a newer trusted URL is provided.
            if ($payload->imageRef !== null) {
                $attributes['image_ref'] = $payload->imageRef;
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

        // Replace rather than sync(): sync() updates/attaches rows one by one, which can
        // transiently violate unique(creature_id, slot) when types swap slots.
        // Callers run inside a transaction, so the replacement is atomic.
        $creature->types()->detach();
        $creature->types()->attach($sync);
    }
}
