<?php

namespace Tests\Feature\Creatures;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Selection\Models\CreatureSelectionAcquisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class CatalogSelectionFlagsTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_guest_cannot_select(): void
    {
        Creature::factory()->create(['slug' => 'bulbasaur', 'name' => 'Bulbasaur']);

        $this->getJson('/api/creatures')
            ->assertOk()
            ->assertJsonPath('data.0.can_select', false)
            ->assertJsonPath('data.0.selection_action', null)
            ->assertJsonPath('data.0.selection_blocked_reason', 'unauthenticated');
    }

    public function test_empty_collection_only_starters_are_selectable(): void
    {
        $this->seedStarters();
        Creature::factory()->create(['slug' => 'mewtwo', 'name' => 'Mewtwo']);
        $user = $this->makePlayer();

        $response = $this->withHeaders($this->bearerFor($user))->getJson('/api/creatures?per_page=50')->assertOk();

        $bySlug = collect($response->json('data'))->keyBy('slug');

        $this->assertTrue($bySlug['bulbasaur']['can_select']);
        $this->assertSame('starter', $bySlug['bulbasaur']['selection_action']);
        $this->assertNull($bySlug['bulbasaur']['selection_blocked_reason']);

        $this->assertFalse($bySlug['mewtwo']['can_select']);
        $this->assertSame('starter_required', $bySlug['mewtwo']['selection_blocked_reason']);
    }

    public function test_owned_creature_is_not_selectable(): void
    {
        $this->seedStarters();
        $user = $this->makePlayer();
        $starter = Creature::query()->where('slug', 'bulbasaur')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $starter->id,
            'is_active' => true,
        ]);
        CreatureSelectionAcquisition::query()->create([
            'user_id' => $user->id,
            'owned_creature_id' => $owned->id,
            'creature_id' => $starter->id,
            'original_evolution_stage' => 1,
            'source' => 'starter',
            'trainer_level_at_acquisition' => 1,
            'idempotency_key' => 'test-starter-'.$owned->id,
        ]);

        // Level 1 has only 1 stage-1 slot; after starter, no allowance left.
        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/creatures/bulbasaur')
            ->assertOk()
            ->assertJsonPath('data.is_owned', true)
            ->assertJsonPath('data.can_select', false)
            ->assertJsonPath('data.selection_blocked_reason', 'owned');

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/creatures/charmander')
            ->assertOk()
            ->assertJsonPath('data.can_select', false)
            ->assertJsonPath('data.selection_blocked_reason', 'no_allowance');
    }

    public function test_level_two_can_acquire_another_stage_one(): void
    {
        $this->seedStarters();
        $user = $this->makePlayer(progress: ['total_xp' => 100, 'level' => 2]);
        $starter = Creature::query()->where('slug', 'bulbasaur')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $starter->id,
            'is_active' => true,
        ]);
        CreatureSelectionAcquisition::query()->create([
            'user_id' => $user->id,
            'owned_creature_id' => $owned->id,
            'creature_id' => $starter->id,
            'original_evolution_stage' => 1,
            'source' => 'starter',
            'trainer_level_at_acquisition' => 1,
            'idempotency_key' => 'test-starter-l2-'.$owned->id,
        ]);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/creatures/charmander')
            ->assertOk()
            ->assertJsonPath('data.can_select', true)
            ->assertJsonPath('data.selection_action', 'acquire')
            ->assertJsonPath('data.selection_blocked_reason', null);
    }
}
