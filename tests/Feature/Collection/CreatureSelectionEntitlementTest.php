<?php

namespace Tests\Feature\Collection;

use App\Domain\Creatures\Models\Creature;
use App\Domain\Selection\Models\CreatureSelectionAcquisition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class CreatureSelectionEntitlementTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedStarters();
    }

    public function test_me_includes_selection_allowances_and_pvp_flag(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.trainer.level', 1)
            ->assertJsonPath('data.trainer.selection_allowances.0.evolution_stage', 1)
            ->assertJsonPath('data.trainer.selection_allowances.0.total', 1)
            ->assertJsonPath('data.trainer.selection_allowances.0.used', 0)
            ->assertJsonPath('data.trainer.selection_allowances.0.remaining', 1)
            ->assertJsonPath('data.trainer.selection_allowances.1.total', 0)
            ->assertJsonPath('data.trainer.pvp_level_unlocked', false);
    }

    public function test_starter_consumes_stage1_entitlement_and_writes_ledger(): void
    {
        $user = $this->makePlayer();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertCreated();

        $this->assertDatabaseHas('creature_selection_acquisitions', [
            'user_id' => $user->id,
            'original_evolution_stage' => 1,
            'source' => 'starter',
        ]);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.trainer.selection_allowances.0.used', 1)
            ->assertJsonPath('data.trainer.selection_allowances.0.remaining', 0);
    }

    public function test_level_one_cannot_acquire_second_stage1_creature(): void
    {
        $user = $this->makePlayer();
        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertCreated();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/creatures/acquire', [
                'creature_slug' => 'charmander',
                'idempotency_key' => 'acq-charmander-1',
            ])
            ->assertForbidden();
    }

    public function test_level_two_can_acquire_second_stage1_creature(): void
    {
        $user = $this->makePlayer(progress: ['total_xp' => 100, 'level' => 2]);
        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertCreated();

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/creatures/acquire', [
                'creature_slug' => 'charmander',
                'idempotency_key' => 'acq-charmander-2',
            ])
            ->assertCreated()
            ->assertJsonPath('data.creature.slug', 'charmander');

        $this->assertSame(2, CreatureSelectionAcquisition::query()->where('user_id', $user->id)->count());
    }

    public function test_stage2_locked_before_level_ten(): void
    {
        $user = $this->makePlayer(progress: ['total_xp' => 1000, 'level' => 5]);
        Creature::factory()->create([
            'slug' => 'ivysaur',
            'name' => 'Ivysaur',
            'evolution_stage' => 2,
            'is_default_form' => true,
            'evolves_from_id' => null,
            'external_id' => 9001,
            'species_external_id' => 9001,
        ]);

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/creatures/acquire', [
                'creature_slug' => 'ivysaur',
                'idempotency_key' => 'acq-ivy-early',
            ])
            ->assertForbidden();
    }

    public function test_stage2_available_at_level_ten(): void
    {
        $user = $this->makePlayer(progress: ['total_xp' => 4500, 'level' => 10]);
        Creature::factory()->create([
            'slug' => 'ivysaur',
            'name' => 'Ivysaur',
            'evolution_stage' => 2,
            'is_default_form' => true,
            'evolves_from_id' => null,
            'external_id' => 9002,
            'species_external_id' => 9002,
        ]);

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/creatures/acquire', [
                'creature_slug' => 'ivysaur',
                'idempotency_key' => 'acq-ivy-ok',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('creature_selection_acquisitions', [
            'user_id' => $user->id,
            'original_evolution_stage' => 2,
            'source' => 'catalog_selection',
        ]);
    }

    public function test_stage3_never_selectable(): void
    {
        $user = $this->makePlayer(progress: ['total_xp' => 19000, 'level' => 20]);
        Creature::factory()->create([
            'slug' => 'venusaur',
            'name' => 'Venusaur',
            'evolution_stage' => 3,
            'is_default_form' => true,
            'evolves_from_id' => null,
            'external_id' => 9003,
            'species_external_id' => 9003,
        ]);

        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/creatures/acquire', [
                'creature_slug' => 'venusaur',
                'idempotency_key' => 'acq-venusaur',
            ])
            ->assertForbidden();
    }

    public function test_acquire_idempotency_does_not_double_consume(): void
    {
        $user = $this->makePlayer(progress: ['total_xp' => 100, 'level' => 2]);

        $payload = [
            'creature_slug' => 'bulbasaur',
            'idempotency_key' => 'same-acquire-key',
        ];

        $this->withHeaders($this->bearerFor($user))->postJson('/api/me/creatures/acquire', $payload)->assertCreated();
        $this->withHeaders($this->bearerFor($user))->postJson('/api/me/creatures/acquire', $payload)->assertCreated();

        $this->assertSame(1, CreatureSelectionAcquisition::query()->where('user_id', $user->id)->count());
    }

    public function test_ledger_stage_remains_after_catalog_stage_change_simulation(): void
    {
        $user = $this->makePlayer();
        $this->withHeaders($this->bearerFor($user))
            ->postJson('/api/me/starter', ['creature_slug' => 'bulbasaur'])
            ->assertCreated();

        $acquisition = CreatureSelectionAcquisition::query()->where('user_id', $user->id)->firstOrFail();
        $creature = Creature::query()->findOrFail($acquisition->creature_id);
        $creature->update(['evolution_stage' => 2]);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.trainer.selection_allowances.0.used', 1)
            ->assertJsonPath('data.trainer.selection_allowances.1.used', 0);
    }

    public function test_pvp_unlocks_at_level_six_in_me_payload(): void
    {
        $user = $this->makePlayer(progress: ['total_xp' => 1500, 'level' => 6]);

        $this->withHeaders($this->bearerFor($user))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.trainer.pvp_level_unlocked', true);
    }
}
