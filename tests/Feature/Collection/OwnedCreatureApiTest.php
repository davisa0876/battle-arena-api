<?php

namespace Tests\Feature\Collection;

use App\Domain\Creatures\Models\Creature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnedCreatureApiTest extends TestCase
{
    use RefreshDatabase;

    private function baseCreature(string $slug = 'bulbasaur'): Creature
    {
        return Creature::factory()->create([
            'slug' => $slug,
            'name' => ucfirst($slug),
            'is_default_form' => true,
            'evolves_from_id' => null,
            'evolution_stage' => 1,
        ]);
    }

    public function test_starter_selection_is_idempotent_for_same_player(): void
    {
        $bulbasaur = $this->baseCreature('bulbasaur');
        Creature::factory()->evolutionOf($bulbasaur, 2)->create([
            'slug' => 'ivysaur',
            'name' => 'Ivysaur',
        ]);
        $this->baseCreature('charmander');
        $user = User::factory()->withPlayer(['handle' => 'starterfan'])->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertCreated()
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.creature.slug', 'bulbasaur')
            ->assertJsonPath('data.evolution.options.0.locked', true)
            ->assertJsonPath('data.evolution.options.0.eligible', false);

        $this->postJson('/api/me/starter', ['creature_slug' => 'charmander'])
            ->assertStatus(409);

        $this->assertDatabaseCount('owned_creatures', 1);
    }

    public function test_evolved_creature_cannot_be_starter(): void
    {
        $base = $this->baseCreature('charmander');
        Creature::factory()->evolutionOf($base, 2)->create([
            'slug' => 'charmeleon',
            'name' => 'Charmeleon',
        ]);

        config(['game.starters.creature_slugs' => ['charmeleon']]);

        $user = User::factory()->withPlayer(['handle' => 'cheatattempt'])->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/me/starter', ['creature_slug' => 'charmeleon'])
            ->assertStatus(422);

        $this->assertDatabaseCount('owned_creatures', 0);
    }

    public function test_player_cannot_view_another_players_owned_creature(): void
    {
        $this->baseCreature('squirtle');
        $owner = User::factory()->withPlayer(['handle' => 'owner'])->create();
        Sanctum::actingAs($owner);
        $ownedId = $this->postJson('/api/me/starter', ['creature_slug' => 'squirtle'])
            ->json('data.id');

        $intruder = User::factory()->withPlayer(['handle' => 'intruder'])->create();
        Sanctum::actingAs($intruder);

        $this->getJson('/api/me/creatures/'.$ownedId)->assertForbidden();
    }
}
