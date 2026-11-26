<?php

namespace Tests\Unit\Application\Battles;

use App\Application\Battles\CombatantFactory;
use App\Application\Battles\SeedCombatDataService;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\Models\CreatureLearnableMove;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CombatantFactoryLoadoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_learnset_falls_back_to_type_matching_mvp_moves(): void
    {
        app(SeedCombatDataService::class)->seed();

        $rock = CreatureType::query()->firstOrCreate(
            ['slug' => 'rock'],
            ['name' => 'Rock', 'provider' => 'pokeapi', 'external_id' => 6],
        );
        $flying = CreatureType::query()->firstOrCreate(
            ['slug' => 'flying'],
            ['name' => 'Flying', 'provider' => 'pokeapi', 'external_id' => 3],
        );

        $creature = Creature::factory()->create([
            'slug' => 'aerodactyl-test',
            'name' => 'Aerodactyl Test',
            'external_id' => 142001,
            'species_external_id' => 142001,
        ]);
        $creature->types()->sync([
            $rock->id => ['slot' => 1],
            $flying->id => ['slot' => 2],
        ]);

        CreatureLearnableMove::query()->where('creature_id', $creature->id)->delete();

        $snap = app(CombatantFactory::class)->fromCreature(
            $creature->fresh(['types']),
            1,
            CombatSide::Player,
        );

        $this->assertNotEmpty($snap->moves);
        $slugs = array_map(static fn ($m) => $m->slug, $snap->moves);
        $this->assertTrue(
            in_array('rock-throw', $slugs, true)
            || in_array('gust', $slugs, true)
            || in_array('peck', $slugs, true)
            || in_array('tackle', $slugs, true),
            'Expected rock/flying or normal MVP fallback moves, got: '.implode(',', $slugs),
        );
    }
}
