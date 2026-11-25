<?php

declare(strict_types=1);

namespace App\Domain\Battle;

/**
 * Versioned elemental multipliers: chart[attackType][defendType] => multiplier (default 1).
 */
final readonly class TypeEffectivenessChart
{
    /**
     * @param  array<string, array<string, int|float>>  $chart
     */
    public function __construct(private array $chart = []) {}

    /**
     * Product of the multipliers against every defending type. 0 means immunity.
     *
     * @param  list<string>  $defendTypes
     */
    public function multiplier(string $attackType, array $defendTypes): float
    {
        $product = 1.0;
        foreach ($defendTypes as $defendType) {
            $product *= (float) ($this->chart[$attackType][$defendType] ?? 1.0);
        }

        return $product;
    }

    /**
     * @return array<string, array<string, int|float>>
     */
    public function toArray(): array
    {
        return $this->chart;
    }
}
