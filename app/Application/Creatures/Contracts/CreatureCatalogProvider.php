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
     * @param  list<int>  $externalIds
     * @return list<ExternalCreatureData>
     *
     * @throws CreatureProviderException
     */
    public function fetchByExternalIds(array $externalIds): array;
}
