<?php

namespace Tests\Feature\Creatures;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class CatalogOwnershipTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_guest_catalog_marks_creatures_as_not_owned(): void
    {
        Creature::factory()->create(['slug' => 'bulbasaur', 'name' => 'Bulbasaur']);

        $this->getJson('/api/creatures')
            ->assertOk()
            ->assertJsonPath('data.0.is_owned', false);
    }

    public function test_authenticated_catalog_marks_owned_creatures(): void
    {
        $user = $this->makePlayer();
        $owned = Creature::factory()->create(['slug' => 'bulbasaur', 'name' => 'Bulbasaur']);
        $other = Creature::factory()->create(['slug' => 'charmander', 'name' => 'Charmander']);

        OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $owned->id,
            'is_active' => true,
        ]);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/creatures')
            ->assertOk()
            ->assertJsonFragment(['slug' => 'bulbasaur', 'is_owned' => true])
            ->assertJsonFragment(['slug' => 'charmander', 'is_owned' => false]);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/creatures/bulbasaur')
            ->assertOk()
            ->assertJsonPath('data.is_owned', true);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/creatures/charmander')
            ->assertOk()
            ->assertJsonPath('data.is_owned', false);

        unset($other);
    }
}
