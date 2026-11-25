<?php

namespace Tests\Unit\Infrastructure\External\PokeApi;

use App\Infrastructure\External\PokeApi\PokeApiCreatureMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PokeApiCreatureCryMappingTest extends TestCase
{
    #[Test]
    public function test_maps_trusted_cries_from_provider_payload(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pokeapi/pokemon_1.json')),
            true,
        );

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/latest/1.ogg',
            $dto->cryLatestUrl,
        );
        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/legacy/1.ogg',
            $dto->cryLegacyUrl,
        );
    }

    #[Test]
    public function test_ignores_untrusted_cry_hosts(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pokeapi/pokemon_1.json')),
            true,
        );
        $payload['cries'] = [
            'latest' => 'https://evil.example/latest.ogg',
            'legacy' => 'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/legacy/1.ogg',
        ];

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertNull($dto->cryLatestUrl);
        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/cries/main/cries/pokemon/legacy/1.ogg',
            $dto->cryLegacyUrl,
        );
    }
}
