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

    public function test_dark_creatures_get_more_than_one_mvp_attack(): void
    {
        app(SeedCombatDataService::class)->seed();

        $dark = CreatureType::query()->firstOrCreate(
            ['slug' => 'dark'],
            ['name' => 'Dark', 'provider' => 'pokeapi', 'external_id' => 17],
        );
        $creature = Creature::factory()->create([
            'slug' => 'absol-loadout-test',
            'name' => 'Absol Loadout',
            'external_id' => 359001,
            'species_external_id' => 359001,
        ]);
        $creature->types()->sync([$dark->id => ['slot' => 1]]);

        $snap = app(CombatantFactory::class)->fromCreature(
            $creature->fresh(['types']),
            1,
            CombatSide::Player,
        );

        $slugs = array_map(static fn ($m) => $m->slug, $snap->moves);
        $this->assertGreaterThanOrEqual(3, count($slugs));
        $this->assertContains('bite', $slugs);
        $this->assertContains('crunch', $slugs);
        $this->assertContains('night-slash', $slugs);
    }

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
        // Uncapped: all typed MVP matches should appear (rock + flying), not a 4-move slice with normals.
        $this->assertContains('rock-throw', $slugs);
        $this->assertContains('gust', $slugs);
        $this->assertContains('peck', $slugs);
        $this->assertNotContains('tackle', $slugs);
    }

    public function test_learnset_is_not_soft_capped_at_four_when_max_moves_is_null(): void
    {
        config(['game.pve.max_moves' => null]);
        app(SeedCombatDataService::class)->seed();

        $normal = CreatureType::query()->firstOrCreate(
            ['slug' => 'normal'],
            ['name' => 'Normal', 'provider' => 'pokeapi', 'external_id' => 1],
        );
        $creature = Creature::factory()->create([
            'slug' => 'rattata-uncapped-test',
            'name' => 'Rattata Uncapped',
            'external_id' => 19002,
            'species_external_id' => 19002,
        ]);
        $creature->types()->sync([$normal->id => ['slot' => 1]]);
        CreatureLearnableMove::query()->where('creature_id', $creature->id)->delete();

        // Attach more than four normal MVP moves explicitly.
        $moves = \App\Domain\Battle\Models\Move::query()
            ->where('is_mvp_supported', true)
            ->where('type_slug', 'normal')
            ->where('power', '>', 0)
            ->orderBy('id')
            ->take(5)
            ->get();
        $this->assertGreaterThanOrEqual(4, $moves->count());
        foreach ($moves as $move) {
            CreatureLearnableMove::query()->updateOrCreate(
                [
                    'creature_id' => $creature->id,
                    'move_id' => $move->id,
                    'version_group' => (string) config('game.pve.version_group'),
                ],
                ['learn_level' => 1, 'learn_method' => 'level-up'],
            );
        }

        $snap = app(CombatantFactory::class)->fromCreature(
            $creature->fresh(['types']),
            1,
            CombatSide::Player,
        );

        $this->assertCount($moves->count(), $snap->moves);
    }
}
