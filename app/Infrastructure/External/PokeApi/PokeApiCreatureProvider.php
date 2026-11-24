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
                $speciesPayload = $this->resolveSpeciesPayload($payload);
                $results[] = $this->mapper->map($payload, $speciesPayload);
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

    /**
     * @return list<int>
     */
    public function listNationalDexSpeciesIds(): array
    {
        return $this->client->listSpeciesExternalIds();
    }

    /**
     * @param  list<int>  $speciesExternalIds
     * @return list<ExternalCreatureData>
     */
    public function fetchBySpeciesExternalIds(array $speciesExternalIds): array
    {
        $results = [];
        $errors = [];

        foreach ($speciesExternalIds as $speciesExternalId) {
            try {
                $speciesPayload = $this->client->getPokemonSpecies($speciesExternalId);
                $pokemonId = $this->defaultPokemonIdFromSpecies($speciesPayload);
                if ($pokemonId === null) {
                    $errors[] = "Species {$speciesExternalId} has no default pokemon variety.";
                    continue;
                }

                $payload = $this->client->getPokemon($pokemonId);
                $results[] = $this->mapper->map($payload, $speciesPayload);
            } catch (CreatureProviderException $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($results === [] && $errors !== []) {
            throw new CreatureProviderException(
                'PokéAPI species import failed for all requested IDs: '.implode(' | ', $errors)
            );
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $pokemonPayload
     * @return array<string, mixed>|null
     */
    private function resolveSpeciesPayload(array $pokemonPayload): ?array
    {
        $speciesUrl = data_get($pokemonPayload, 'species.url');
        if (! is_string($speciesUrl) || preg_match('#/pokemon-species/(\d+)/?#', $speciesUrl, $matches) !== 1) {
            return null;
        }

        try {
            return $this->client->getPokemonSpecies((int) $matches[1]);
        } catch (CreatureProviderException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $speciesPayload
     */
    private function defaultPokemonIdFromSpecies(array $speciesPayload): ?int
    {
        foreach ($speciesPayload['varieties'] ?? [] as $variety) {
            if (! is_array($variety) || empty($variety['is_default'])) {
                continue;
            }

            $url = data_get($variety, 'pokemon.url');
            if (is_string($url) && preg_match('#/pokemon/(\d+)/?#', $url, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return null;
    }
}
