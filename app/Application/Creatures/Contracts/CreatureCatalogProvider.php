<?php

namespace App\Application\Creatures\Contracts;

use App\Application\Creatures\DTOs\ExternalCreatureData;
use App\Application\Creatures\Exceptions\CreatureProviderException;

/**
 * Abstraction over external creature data sources.
 * Domain and API layers must never depend on a concrete provider response shape.
 */
interface CreatureCatalogProvider
{
    public function providerName(): string;

    /**
     * @param  list<int>  $externalIds Pokemon / variety external IDs
     * @return list<ExternalCreatureData>
     *
     * @throws CreatureProviderException
     */
    public function fetchByExternalIds(array $externalIds): array;

    /**
     * National-dex species IDs for a full catalog sync.
     *
     * @return list<int>
     *
     * @throws CreatureProviderException
     */
    public function listNationalDexSpeciesIds(): array;

    /**
     * Fetch default varieties for the given species IDs (includes evolution metadata).
     *
     * @param  list<int>  $speciesExternalIds
     * @return list<ExternalCreatureData>
     *
     * @throws CreatureProviderException
     */
    public function fetchBySpeciesExternalIds(array $speciesExternalIds): array;
}
