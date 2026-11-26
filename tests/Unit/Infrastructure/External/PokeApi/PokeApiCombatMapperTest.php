<?php

namespace Tests\Unit\Infrastructure\External\PokeApi;

use App\Infrastructure\External\PokeApi\PokeApiCombatMapper;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PokeApiCombatMapperTest extends TestCase
{
    private PokeApiCombatMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new PokeApiCombatMapper;
    }

    #[Test]
    public function test_map_move_reads_english_name_and_stats(): void
    {
        $dto = $this->mapper->mapMove([
            'id' => 33,
            'name' => 'tackle',
            'power' => 40,
            'accuracy' => 100,
            'pp' => 35,
            'priority' => 0,
            'damage_class' => ['name' => 'physical'],
            'type' => ['name' => 'normal'],
            'names' => [
                ['name' => 'Charge', 'language' => ['name' => 'fr']],
                ['name' => 'Tackle', 'language' => ['name' => 'en']],
            ],
        ]);

        $this->assertSame('pokeapi', $dto->provider);
        $this->assertSame(33, $dto->externalId);
        $this->assertSame('tackle', $dto->slug);
        $this->assertSame('Tackle', $dto->name);
        $this->assertSame(40, $dto->power);
        $this->assertTrue($dto->isEngineEligible());
    }

    #[Test]
    public function test_status_moves_are_not_engine_eligible(): void
    {
        $dto = $this->mapper->mapMove([
            'id' => 14,
            'name' => 'swords-dance',
            'power' => null,
            'accuracy' => null,
            'pp' => 20,
            'priority' => 0,
            'damage_class' => ['name' => 'status'],
            'type' => ['name' => 'normal'],
            'names' => [],
        ]);

        $this->assertFalse($dto->isEngineEligible());
    }

    #[Test]
    public function test_learnset_filters_version_group_and_prefers_level_up(): void
    {
        $refs = $this->mapper->learnsetForVersionGroup([
            'moves' => [
                [
                    'move' => ['name' => 'tackle', 'url' => 'https://pokeapi.co/api/v2/move/33/'],
                    'version_group_details' => [
                        [
                            'level_learned_at' => 1,
                            'move_learn_method' => ['name' => 'level-up'],
                            'version_group' => ['name' => 'scarlet-violet'],
                        ],
                        [
                            'level_learned_at' => 0,
                            'move_learn_method' => ['name' => 'machine'],
                            'version_group' => ['name' => 'scarlet-violet'],
                        ],
                    ],
                ],
                [
                    'move' => ['name' => 'ember', 'url' => 'https://pokeapi.co/api/v2/move/52/'],
                    'version_group_details' => [
                        [
                            'level_learned_at' => 9,
                            'move_learn_method' => ['name' => 'level-up'],
                            'version_group' => ['name' => 'red-blue'],
                        ],
                    ],
                ],
                [
                    'move' => ['name' => 'growl', 'url' => 'https://pokeapi.co/api/v2/move/45/'],
                    'version_group_details' => [
                        [
                            'level_learned_at' => 1,
                            'move_learn_method' => ['name' => 'level-up'],
                            'version_group' => ['name' => 'scarlet-violet'],
                        ],
                    ],
                ],
            ],
        ], 'scarlet-violet');

        $this->assertCount(2, $refs);
        $bySlug = collect($refs)->keyBy(fn ($r) => $r->moveSlug);

        $this->assertSame(33, $bySlug['tackle']->moveExternalId);
        $this->assertSame('level-up', $bySlug['tackle']->learnMethod);
        $this->assertSame(1, $bySlug['tackle']->learnLevel);
        $this->assertArrayHasKey('growl', $bySlug->all());
        $this->assertArrayNotHasKey('ember', $bySlug->all());
    }
}
