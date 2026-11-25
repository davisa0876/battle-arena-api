<?php

namespace Tests\Feature\Collection;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class StarterAndCollectionTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_starter_endpoints_require_authentication(): void
    {
        $this->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])->assertUnauthorized();
        $this->getJson('/api/me/creatures')->assertUnauthorized();
        $this->getJson('/api/me/creatures/1')->assertUnauthorized();
        $this->patchJson('/api/me/creatures/1/select')->assertUnauthorized();
    }

    public function test_every_configured_starter_can_be_chosen(): void
    {
        $this->seedStarters();

        foreach (config('game.starters.creature_slugs') as $slug) {
            $user = $this->makePlayer();
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();

            $this->withHeaders($this->bearerFor($user))
                ->postJson('/api/me/starter', ['creature_slug' => $slug])
                ->assertCreated()
                ->assertJsonPath('data.creature.slug', $slug)
                ->assertJsonPath('data.level', 1)
                ->assertJsonPath('data.total_xp', 0)
                ->assertJsonPath('data.is_active', true);
        }
    }

    public function test_starter_slug_is_case_insensitive_and_updates_onboarding_state(): void
    {
        $this->seedStarters();
        $user = $this->makePlayer();
        $headers = $this->bearerFor($user);

        $this->withHeaders($headers)->postJson('/api/me/starter', ['creature_slug' => ' Pikachu '])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->getJson('/api/me')->assertJsonPath('data.onboarding.starter_selected', true);
    }

    public function test_second_starter_selection_is_rejected_even_for_a_different_creature(): void
    {
        $this->seedStarters();
        $user = $this->makePlayer();
        $headers = $this->bearerFor($user);

        $this->withHeaders($headers)->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)
            ->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'You have already chosen a starter creature.');

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->postJson('/api/me/starter', ['creature_slug' => 'eevee'])->assertStatus(409);

        $this->assertSame(1, OwnedCreature::query()->where('user_id', $user->id)->count());
    }

    public function test_slug_outside_the_starter_list_is_rejected_at_validation(): void
    {
        $this->seedStarters();
        Creature::factory()->create(['slug' => 'mewtwo']);
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'mewtwo'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('creature_slug');

        $this->assertDatabaseCount('owned_creatures', 0);
    }

    public function test_starter_missing_from_the_catalog_is_unprocessable(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This starter creature is not available right now.');
    }

    public function test_a_configured_slug_that_is_not_a_base_form_is_rejected(): void
    {
        $parent = Creature::factory()->create(['slug' => 'charmander']);
        Creature::factory()->evolutionOf($parent)->create(['slug' => 'charmeleon']);
        config(['game.starters.creature_slugs' => ['charmeleon']]);
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'charmeleon'])
            ->assertUnprocessable();

        $this->assertDatabaseCount('owned_creatures', 0);
    }

    public function test_non_default_forms_are_not_valid_starters(): void
    {
        Creature::factory()->create(['slug' => 'pikachu', 'is_default_form' => false]);
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'pikachu'])
            ->assertUnprocessable();
    }

    public function test_collection_lists_only_my_creatures_active_first_with_locked_evolutions(): void
    {
        $base = Creature::factory()->create(['slug' => 'eevee']);
        Creature::factory()->evolutionOf($base)->create(['slug' => 'vaporeon', 'name' => 'Vaporeon']);
        Creature::factory()->evolutionOf($base)->create(['slug' => 'jolteon', 'name' => 'Jolteon']);
        $other = Creature::factory()->create(['slug' => 'rattata']);

        $user = $this->makePlayer();
        $stranger = $this->makePlayer();
        OwnedCreature::factory()->create(['user_id' => $user->id, 'creature_id' => $other->id, 'acquired_at' => now()->subDay()]);
        $active = OwnedCreature::factory()->active()->create(['user_id' => $user->id, 'creature_id' => $base->id]);
        OwnedCreature::factory()->create(['user_id' => $stranger->id, 'creature_id' => $base->id]);

        $response = $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/me/creatures')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonPath('data.0.creature.slug', 'eevee')
            ->assertJsonPath('data.0.evolution.execution_enabled', false)
            ->assertJsonCount(2, 'data.0.evolution.options')
            ->assertJsonPath('data.1.is_active', false)
            ->assertJsonPath('data.1.evolution.options', []);

        foreach ($response->json('data.0.evolution.options') as $option) {
            $this->assertFalse($option['eligible']);
            $this->assertTrue($option['locked']);
            $this->assertSame('requirements_not_defined', $option['reason_code']);
            $this->assertSame('Evolution is not available yet.', $option['reason']);
            $this->assertArrayHasKey('slug', $option['creature']);
        }
    }

    public function test_owned_creature_detail_and_ownership_policy(): void
    {
        $owner = $this->makePlayer();
        $intruder = $this->makePlayer();
        $owned = OwnedCreature::factory()->active()->create(['user_id' => $owner->id]);

        $this->withHeaders($this->bearerFor($owner))
            ->getJson("/api/me/creatures/{$owned->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $owned->id)
            ->assertJsonStructure(['data' => ['creature' => ['name', 'slug', 'types', 'base_stats'], 'evolution' => ['options']]]);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearerFor($intruder))
            ->getJson("/api/me/creatures/{$owned->id}")
            ->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->withHeaders($this->bearerFor($intruder))
            ->patchJson("/api/me/creatures/{$owned->id}/select")
            ->assertForbidden();

        $this->assertTrue($owned->fresh()->is_active);
    }

    public function test_unknown_owned_creature_returns_a_localized_404(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user) + ['Accept-Language' => 'it'])
            ->getJson('/api/me/creatures/9999')
            ->assertNotFound()
            ->assertJsonPath('message', 'Creatura posseduta non trovata.');
    }

    public function test_selecting_a_creature_makes_it_the_only_active_one_and_is_idempotent(): void
    {
        $user = $this->makePlayer();
        $first = OwnedCreature::factory()->active()->create(['user_id' => $user->id]);
        $second = OwnedCreature::factory()->create(['user_id' => $user->id]);
        $headers = $this->bearerFor($user);

        $this->withHeaders($headers)
            ->patchJson("/api/me/creatures/{$second->id}/select")
            ->assertOk()
            ->assertJsonPath('data.id', $second->id)
            ->assertJsonPath('data.is_active', true);

        $this->assertFalse($first->fresh()->is_active);
        $this->assertNull($first->fresh()->active_user_id);
        $this->assertSame($user->id, (int) $second->fresh()->active_user_id);

        $this->app['auth']->forgetGuards();
        $this->withHeaders($headers)->patchJson("/api/me/creatures/{$second->id}/select")->assertOk();

        $this->assertSame(1, OwnedCreature::query()->where('user_id', $user->id)->where('is_active', true)->count());
    }

    public function test_selecting_does_not_touch_other_players_active_creatures(): void
    {
        $user = $this->makePlayer();
        $other = $this->makePlayer();
        $otherActive = OwnedCreature::factory()->active()->create(['user_id' => $other->id]);
        $mine = OwnedCreature::factory()->create(['user_id' => $user->id]);

        $this->withHeaders($this->bearerFor($user))->patchJson("/api/me/creatures/{$mine->id}/select")->assertOk();

        $this->assertTrue($otherActive->fresh()->is_active);
    }

    public function test_database_refuses_two_active_creatures_for_one_trainer(): void
    {
        $user = $this->makePlayer();
        OwnedCreature::factory()->active()->create(['user_id' => $user->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        OwnedCreature::factory()->active()->create(['user_id' => $user->id]);
    }

    public function test_database_refuses_owning_the_same_creature_twice(): void
    {
        $user = $this->makePlayer();
        $creature = Creature::factory()->create();
        OwnedCreature::factory()->create(['user_id' => $user->id, 'creature_id' => $creature->id]);

        $this->expectException(UniqueConstraintViolationException::class);
        OwnedCreature::factory()->create(['user_id' => $user->id, 'creature_id' => $creature->id]);
    }

    public function test_catalog_endpoints_still_work_unchanged(): void
    {
        $creature = Creature::factory()->create(['slug' => 'bulbasaur']);

        $this->getJson('/api/creatures')->assertOk()->assertJsonPath('data.0.slug', 'bulbasaur');
        $this->getJson('/api/creatures/bulbasaur')->assertOk()->assertJsonPath('data.id', $creature->id);
    }
}
