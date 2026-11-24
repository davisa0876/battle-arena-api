<?php

namespace Tests\Unit;

use App\Infrastructure\External\PokeApi\PokeApiCreatureMapper;
use PHPUnit\Framework\Attributes\UnitTest;
use Tests\TestCase;

class PokeApiCreatureMapperTest extends TestCase
{
    #[UnitTest]
    public function test_maps_provider_payload_into_domain_dto(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pokeapi/pokemon_1.json')),
            true,
        );

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertTrue($dto->isComplete());
        $this->assertSame('pokeapi', $dto->provider);
        $this->assertSame(1, $dto->externalId);
        $this->assertSame('Bulbasaur', $dto->name);
        $this->assertSame('bulbasaur', $dto->slug);
        $this->assertSame(70, $dto->heightCm);
        $this->assertSame(6900, $dto->weightG);
        $this->assertSame(65, $dto->baseSpecialAttack);
        $this->assertNull($dto->imageRef);
        $this->assertCount(2, $dto->types);
    }

    #[UnitTest]
    public function test_incomplete_payload_is_detected(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pokeapi/pokemon_incomplete.json')),
            true,
        );

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertFalse($dto->isComplete());
    }
}
