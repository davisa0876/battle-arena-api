<?php

namespace Tests\Unit\Application\Creatures;

use App\Application\Creatures\DTOs\ExternalCreatureData;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExternalCreatureDataTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     */
    private function make(array $overrides = []): ExternalCreatureData
    {
        $defaults = [
            'provider' => 'pokeapi',
            'externalId' => 25,
            'name' => 'Pikachu',
            'slug' => 'pikachu',
            'heightCm' => 40,
            'weightG' => 6000,
            'baseHp' => 35,
            'baseAttack' => 55,
            'baseDefense' => 40,
            'baseSpecialAttack' => 50,
            'baseSpecialDefense' => 50,
            'baseSpeed' => 90,
            'types' => [
                ['name' => 'Electric', 'slug' => 'electric', 'slot' => 1],
            ],
            'imageRef' => null,
        ];

        return new ExternalCreatureData(...array_merge($defaults, $overrides));
    }

    #[Test]
    public function test_complete_payload_is_complete(): void
    {
        $this->assertTrue($this->make()->isComplete());
    }

    #[Test]
    public function test_complete_payload_with_multiple_types_and_image_is_complete(): void
    {
        $dto = $this->make([
            'types' => [
                ['name' => 'Grass', 'slug' => 'grass', 'slot' => 1],
                ['name' => 'Poison', 'slug' => 'poison', 'slot' => 2],
            ],
            'imageRef' => 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/1.png',
        ]);

        $this->assertTrue($dto->isComplete());
    }

    #[Test]
    public function test_nullable_height_weight_and_image_do_not_affect_completeness(): void
    {
        $dto = $this->make(['heightCm' => null, 'weightG' => null, 'imageRef' => null]);

        $this->assertTrue($dto->isComplete());
    }

    #[Test]
    public function test_empty_name_is_incomplete(): void
    {
        $this->assertFalse($this->make(['name' => ''])->isComplete());
    }

    #[Test]
    public function test_empty_slug_is_incomplete(): void
    {
        $this->assertFalse($this->make(['slug' => ''])->isComplete());
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidExternalIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    #[Test]
    #[DataProvider('invalidExternalIds')]
    public function test_external_id_below_one_is_incomplete(int $externalId): void
    {
        $this->assertFalse($this->make(['externalId' => $externalId])->isComplete());
    }

    #[Test]
    public function test_external_id_of_one_is_complete(): void
    {
        $this->assertTrue($this->make(['externalId' => 1])->isComplete());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function statProperties(): array
    {
        return [
            'hp' => ['baseHp'],
            'attack' => ['baseAttack'],
            'defense' => ['baseDefense'],
            'special attack' => ['baseSpecialAttack'],
            'special defense' => ['baseSpecialDefense'],
            'speed' => ['baseSpeed'],
        ];
    }

    #[Test]
    #[DataProvider('statProperties')]
    public function test_any_base_stat_of_zero_is_incomplete(string $property): void
    {
        $this->assertFalse($this->make([$property => 0])->isComplete());
    }

    #[Test]
    #[DataProvider('statProperties')]
    public function test_any_negative_base_stat_is_incomplete(string $property): void
    {
        $this->assertFalse($this->make([$property => -5])->isComplete());
    }

    #[Test]
    #[DataProvider('statProperties')]
    public function test_base_stat_of_one_is_complete(string $property): void
    {
        $this->assertTrue($this->make([$property => 1])->isComplete());
    }

    #[Test]
    public function test_empty_types_is_incomplete(): void
    {
        $this->assertFalse($this->make(['types' => []])->isComplete());
    }

    /**
     * @return array<string, array{list<array<string, mixed>>}>
     */
    public static function invalidTypeSets(): array
    {
        return [
            'empty type name' => [[['name' => '', 'slug' => 'fire', 'slot' => 1]]],
            'empty type slug' => [[['name' => 'Fire', 'slug' => '', 'slot' => 1]]],
            'slot zero' => [[['name' => 'Fire', 'slug' => 'fire', 'slot' => 0]]],
            'negative slot' => [[['name' => 'Fire', 'slug' => 'fire', 'slot' => -1]]],
            'missing name key' => [[['slug' => 'fire', 'slot' => 1]]],
            'missing slug key' => [[['name' => 'Fire', 'slot' => 1]]],
            'missing slot key' => [[['name' => 'Fire', 'slug' => 'fire']]],
            'second type invalid' => [[
                ['name' => 'Fire', 'slug' => 'fire', 'slot' => 1],
                ['name' => 'Flying', 'slug' => '', 'slot' => 2],
            ]],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $types
     */
    #[Test]
    #[DataProvider('invalidTypeSets')]
    public function test_malformed_type_rows_are_incomplete(array $types): void
    {
        $this->assertFalse($this->make(['types' => $types])->isComplete());
    }

    #[Test]
    public function test_constructor_exposes_all_values(): void
    {
        $dto = $this->make(['imageRef' => 'https://raw.githubusercontent.com/x.png']);

        $this->assertSame('pokeapi', $dto->provider);
        $this->assertSame(25, $dto->externalId);
        $this->assertSame('Pikachu', $dto->name);
        $this->assertSame('pikachu', $dto->slug);
        $this->assertSame(40, $dto->heightCm);
        $this->assertSame(6000, $dto->weightG);
        $this->assertSame(90, $dto->baseSpeed);
        $this->assertSame('https://raw.githubusercontent.com/x.png', $dto->imageRef);
    }
}
