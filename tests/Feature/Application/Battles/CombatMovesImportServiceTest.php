<?php

namespace Tests\Feature\Application\Battles;

use App\Application\Battles\CombatMovesImportService;
use App\Domain\Battle\Models\CreatureLearnableMove;
use App\Domain\Battle\Models\Move;
use App\Domain\Battle\Models\TypeEffectiveness;
use App\Domain\Creatures\Models\Creature;
use App\Infrastructure\External\PokeApi\PokeApiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CombatMovesImportServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_fixture_import_seeds_moves_and_type_chart(): void
    {
        $result = app(CombatMovesImportService::class)->importFromFixtures();

        $this->assertSame('fixtures', $result['source']);
        $this->assertGreaterThan(0, $result['moves']);
        $this->assertGreaterThan(0, $result['type_rows']);
        $this->assertTrue(Move::query()->where('slug', 'tackle')->exists());
        $this->assertGreaterThan(0, TypeEffectiveness::query()->count());
    }

    #[Test]
    public function test_pokeapi_import_upserts_eligible_moves_and_learnset_for_version_group(): void
    {
        config([
            'creatures.providers.pokeapi.base_url' => 'http://pokeapi.test',
            'creatures.providers.pokeapi.request_delay_ms' => 0,
            'creatures.providers.pokeapi.cache_ttl_seconds' => 0,
            'game.pve.version_group' => 'scarlet-violet',
        ]);
        $this->app->forgetInstance(PokeApiClient::class);
        $this->app->forgetInstance(CombatMovesImportService::class);

        $creature = Creature::factory()->create([
            'provider' => 'pokeapi',
            'external_id' => 359,
            'slug' => 'absol',
            'name' => 'Absol',
        ]);

        // Stale learnable from an older fixture policy — must be removed.
        $stale = Move::query()->create([
            'provider' => 'pokeapi',
            'external_id' => 9999,
            'slug' => 'stale-move',
            'name' => 'Stale',
            'power' => 40,
            'accuracy' => 100,
            'pp' => 20,
            'damage_class' => 'physical',
            'type_slug' => 'normal',
            'priority' => 0,
            'version_group' => 'scarlet-violet',
            'is_mvp_supported' => true,
        ]);
        CreatureLearnableMove::query()->create([
            'creature_id' => $creature->id,
            'move_id' => $stale->id,
            'version_group' => 'scarlet-violet',
            'learn_level' => 1,
            'learn_method' => 'level-up',
        ]);

        Http::fake([
            'pokeapi.test/pokemon/359' => Http::response([
                'id' => 359,
                'name' => 'absol',
                'moves' => [
                    [
                        'move' => ['name' => 'crunch', 'url' => 'https://pokeapi.co/api/v2/move/242/'],
                        'version_group_details' => [[
                            'level_learned_at' => 1,
                            'move_learn_method' => ['name' => 'level-up'],
                            'version_group' => ['name' => 'scarlet-violet'],
                        ]],
                    ],
                    [
                        'move' => ['name' => 'swords-dance', 'url' => 'https://pokeapi.co/api/v2/move/14/'],
                        'version_group_details' => [[
                            'level_learned_at' => 25,
                            'move_learn_method' => ['name' => 'level-up'],
                            'version_group' => ['name' => 'scarlet-violet'],
                        ]],
                    ],
                    [
                        'move' => ['name' => 'night-slash', 'url' => 'https://pokeapi.co/api/v2/move/400/'],
                        'version_group_details' => [[
                            'level_learned_at' => 0,
                            'move_learn_method' => ['name' => 'machine'],
                            'version_group' => ['name' => 'scarlet-violet'],
                        ]],
                    ],
                    [
                        'move' => ['name' => 'bite', 'url' => 'https://pokeapi.co/api/v2/move/44/'],
                        'version_group_details' => [[
                            'level_learned_at' => 10,
                            'move_learn_method' => ['name' => 'level-up'],
                            'version_group' => ['name' => 'diamond-pearl'],
                        ]],
                    ],
                ],
            ], 200),
            'pokeapi.test/move/242' => Http::response($this->movePayload(242, 'crunch', 'physical', 'dark', 80, 100, 15), 200),
            'pokeapi.test/move/14' => Http::response($this->movePayload(14, 'swords-dance', 'status', 'normal', null, null, 20), 200),
            'pokeapi.test/move/400' => Http::response($this->movePayload(400, 'night-slash', 'physical', 'dark', 70, 100, 15), 200),
        ]);

        $result = app(CombatMovesImportService::class)->importFromPokeApi();

        $this->assertSame('pokeapi', $result['source']);
        $this->assertSame(1, $result['creatures']);
        $this->assertSame(0, $result['skipped_creatures']);
        $this->assertSame(2, $result['moves_upserted']);
        $this->assertSame(2, $result['learnable_links']);
        $this->assertGreaterThan(0, $result['type_rows']);

        $this->assertTrue(Move::query()->where('slug', 'crunch')->where('is_mvp_supported', true)->exists());
        $this->assertTrue(Move::query()->where('slug', 'night-slash')->where('is_mvp_supported', true)->exists());
        $this->assertFalse(Move::query()->where('slug', 'swords-dance')->exists());
        $this->assertFalse(Move::query()->where('slug', 'bite')->exists());

        $linkedSlugs = CreatureLearnableMove::query()
            ->where('creature_id', $creature->id)
            ->where('version_group', 'scarlet-violet')
            ->with('move')
            ->get()
            ->pluck('move.slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['crunch', 'night-slash'], $linkedSlugs);
        $this->assertFalse(
            CreatureLearnableMove::query()
                ->where('creature_id', $creature->id)
                ->where('move_id', $stale->id)
                ->exists()
        );

        Http::assertSentCount(4); // 1 pokemon + 3 move lookups (status still fetched then skipped)
    }

    #[Test]
    public function test_import_moves_command_fixture_flag(): void
    {
        $this->artisan('combat:import-moves', ['--fixture' => true])
            ->assertSuccessful();

        $this->assertTrue(Move::query()->where('slug', 'tackle')->exists());
    }

    /**
     * @return array<string, mixed>
     */
    private function movePayload(
        int $id,
        string $slug,
        string $damageClass,
        string $type,
        ?int $power,
        ?int $accuracy,
        int $pp,
    ): array {
        return [
            'id' => $id,
            'name' => $slug,
            'power' => $power,
            'accuracy' => $accuracy,
            'pp' => $pp,
            'priority' => 0,
            'damage_class' => ['name' => $damageClass],
            'type' => ['name' => $type],
            'names' => [
                ['name' => ucwords(str_replace('-', ' ', $slug)), 'language' => ['name' => 'en']],
            ],
        ];
    }
}
