<?php

namespace App\Infrastructure\External\PokeApi;

use App\Application\Creatures\Contracts\CreatureCatalogProvider;
use App\Application\Creatures\DTOs\ExternalCreatureData;
use App\Application\Creatures\Exceptions\CreatureProviderException;

final class PokeApiCreatureProvider implements CreatureCatalogProvider
{
    public function __construct(
        private readonly PokeApiClient $client,
        private readonly PokeApiCreatureMapper $mapper,
    ) {}

    public function providerName(): string
    {
        return 'pokeapi';
    }

    /**
     * @param  list<int>  $externalIds
     * @return list<ExternalCreatureData>
     */
    public function fetchByExternalIds(array $externalIds): array
    {
        $results = [];
        $errors = [];

        foreach ($externalIds as $externalId) {
            try {
                $payload = $this->client->getPokemon($externalId);
                $results[] = $this->mapper->map($payload);
            } catch (CreatureProviderException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($results === [] && $errors !== []) {
            throw new CreatureProviderException(
                'PokéAPI import failed for all requested creatures: '.implode(' | ', $errors)
            );
        }

        return $results;
    }
}
