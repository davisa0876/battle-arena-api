<?php

namespace Tests\Feature\Pve;

use App\Application\Battles\SeedCombatDataService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Progression\Models\PlayerXpEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PveBattleFlowTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCombatCatalog();
    }

    public function test_authenticated_player_can_start_attack_defend_and_finish_with_xp_once(): void
    {
        $user = $this->makePlayer();
        $creature = Creature::query()->where('slug', 'charmander')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $creature->id,
            'level' => 5,
            'is_active' => true,
        ]);

        $headers = $this->bearerFor($user);

        $start = $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
            'difficulty' => 'easy',
        ], $headers);

        $start->assertCreated()
            ->assertJsonPath('data.phase', 'awaiting_player_attack')
            ->assertJsonPath('data.mode', 'pve')
            ->assertJsonStructure([
                'data' => [
                    'combat_log',
                    'player' => ['cry_url', 'cry_latest_url', 'cry_legacy_url'],
                    'opponent' => ['cry_url', 'cry_latest_url', 'cry_legacy_url'],
                ],
            ]);
        $this->assertSame('started', $start->json('data.combat_log.0.kind'));

        $battleId = $start->json('data.id');
        $version = (int) $start->json('data.version');
        $moves = $start->json('data.player.moves') ?? [];
        $this->assertNotEmpty($moves);
        $moveId = (int) $moves[0]['id'];

        // Fight until finished or a safe iteration cap.
        $guard = 0;
        $finished = false;
        $rewardXp = null;
        while ($guard++ < 80 && ! $finished) {
            $state = $this->getJson("/api/pve/battles/{$battleId}", $headers)->assertOk();
            $phase = $state->json('data.phase');
            $version = (int) $state->json('data.version');

            if ($phase === 'finished') {
                $finished = true;
                $rewardXp = $state->json('data.reward.xp_awarded');
                break;
            }

            if ($phase === 'awaiting_player_attack') {
                $moves = collect($state->json('data.player.moves'));
                $usable = $moves->firstWhere('can_use', true);
                $allowed = collect($state->json('data.allowed_actions') ?? []);
                if ($usable) {
                    $payload = ['move_id' => (int) $usable['id'], 'expected_version' => $version];
                } elseif ($allowed->contains('recover') || $moves->contains(fn ($m) => (int) ($m['pp']['current'] ?? 0) > 0)) {
                    $payload = ['recover' => true, 'expected_version' => $version];
                } else {
                    $payload = ['basic_strike' => true, 'expected_version' => $version];
                }

                $response = $this->withHeaders($headers + ['Idempotency-Key' => 'atk-'.$guard])
                    ->postJson("/api/pve/battles/{$battleId}/attack", $payload);
                $response->assertOk();
                if ($response->json('data.battle.phase') === 'finished') {
                    $finished = true;
                    $rewardXp = $response->json('data.reward.xp_awarded');
                }
                continue;
            }

            if ($phase === 'awaiting_player_defense') {
                $response = $this->withHeaders($headers + ['Idempotency-Key' => 'def-'.$guard])
                    ->postJson("/api/pve/battles/{$battleId}/defend", [
                        'defense' => 'endure',
                        'expected_version' => $version,
                    ]);
                $response->assertOk();
                if ($response->json('data.battle.phase') === 'finished') {
                    $finished = true;
                    $rewardXp = $response->json('data.reward.xp_awarded');
                }
            }
        }

        $this->assertTrue($finished, 'Battle should finish within the turn budget.');
        $this->assertContains($rewardXp, [5, 10]);

        $eventsCount = PlayerXpEvent::query()
            ->where('user_id', $user->id)
            ->where('source_type', 'battle_pve')
            ->count();
        $this->assertSame(1, $eventsCount);

        // Replay completion path must not double-award.
        $again = $this->getJson("/api/pve/battles/{$battleId}", $headers)->assertOk();
        $again->assertJsonPath('data.phase', 'finished');
        $this->assertSame(1, PlayerXpEvent::query()->where('user_id', $user->id)->where('source_type', 'battle_pve')->count());
    }

    public function test_wrong_phase_and_foreign_battle_are_rejected(): void
    {
        $owner = $this->makePlayer(['handle' => 'ownerone']);
        $intruder = $this->makePlayer(['handle' => 'intruder']);
        $creature = Creature::query()->where('slug', 'bulbasaur')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $owner->id,
            'creature_id' => $creature->id,
            'level' => 3,
            'is_active' => true,
        ]);

        $start = $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
        ], $this->bearerFor($owner))->assertCreated();

        $battleId = $start->json('data.id');
        $version = (int) $start->json('data.version');

        $this->withHeaders($this->bearerFor($owner) + ['Idempotency-Key' => 'def-early'])
            ->postJson("/api/pve/battles/{$battleId}/defend", [
                'defense' => 'block',
                'expected_version' => $version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'BATTLE_PHASE_CONFLICT');

        $this->assertNotSame($owner->id, $intruder->id);

        $intruderResponse = $this->actingAs($intruder, 'sanctum')
            ->getJson("/api/pve/battles/{$battleId}");
        $intruderResponse
            ->assertNotFound()
            ->assertJsonPath('code', 'BATTLE_NOT_FOUND');
    }

    public function test_leaving_active_battle_counts_as_loss_and_allows_new_start(): void
    {
        $user = $this->makePlayer();
        $creature = Creature::query()->where('slug', 'bulbasaur')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $creature->id,
            'level' => 2,
            'is_active' => true,
        ]);
        $headers = $this->bearerFor($user);

        $first = $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
            'difficulty' => 'easy',
        ], $headers)->assertCreated();

        $battleId = $first->json('data.id');

        $this->postJson("/api/pve/battles/{$battleId}/abandon", [], $headers)
            ->assertOk()
            ->assertJsonPath('data.battle.phase', 'finished')
            ->assertJsonPath('data.battle.outcome', 'loss')
            ->assertJsonPath('data.battle.end_reason', 'abandoned')
            ->assertJsonPath('data.battle.record.wins', 0)
            ->assertJsonPath('data.battle.record.losses', 0)
            ->assertJsonPath('data.battle.record.give_ups', 1)
            ->assertJsonMissingPath('data.battle.reward')
            ->assertJsonPath('data.reward', null);

        $this->assertSame(
            0,
            PlayerXpEvent::query()->where('user_id', $user->id)->where('source_type', 'battle_pve')->count(),
        );

        $this->getJson('/api/me', $headers)
            ->assertOk()
            ->assertJsonPath('data.pve_record.wins', 0)
            ->assertJsonPath('data.pve_record.losses', 0)
            ->assertJsonPath('data.pve_record.give_ups', 1);

        $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
            'difficulty' => 'easy',
        ], $headers)->assertCreated();
    }

    public function test_starting_new_battle_auto_forfeits_previous_active_match(): void
    {
        $user = $this->makePlayer();
        $creature = Creature::query()->where('slug', 'charmander')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $creature->id,
            'level' => 2,
            'is_active' => true,
        ]);
        $headers = $this->bearerFor($user);

        $first = $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
        ], $headers)->assertCreated();
        $oldId = $first->json('data.id');

        $second = $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
        ], $headers)->assertCreated();

        $this->assertNotSame($oldId, $second->json('data.id'));
        $this->getJson("/api/pve/battles/{$oldId}", $headers)
            ->assertOk()
            ->assertJsonPath('data.phase', 'finished')
            ->assertJsonPath('data.outcome', 'loss')
            ->assertJsonPath('data.end_reason', 'abandoned')
            ->assertJsonPath('data.record.give_ups', 1);
    }

    public function test_attack_idempotency_returns_same_result_without_double_spend(): void
    {
        $user = $this->makePlayer();
        $creature = Creature::query()->where('slug', 'squirtle')->firstOrFail();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $creature->id,
            'level' => 4,
            'is_active' => true,
        ]);

        $headers = $this->bearerFor($user);
        $start = $this->postJson('/api/pve/battles', [
            'owned_creature_id' => $owned->id,
            'difficulty' => 'easy',
        ], $headers)->assertCreated();

        $battleId = $start->json('data.id');
        $version = (int) $start->json('data.version');
        $moveId = (int) $start->json('data.player.moves.0.id');
        $ppBefore = (int) $start->json('data.player.moves.0.pp.current');

        $payload = ['move_id' => $moveId, 'expected_version' => $version];
        $keyHeaders = $headers + ['Idempotency-Key' => 'same-key-1'];

        $first = $this->withHeaders($keyHeaders)->postJson("/api/pve/battles/{$battleId}/attack", $payload)->assertOk();
        $second = $this->withHeaders($keyHeaders)->postJson("/api/pve/battles/{$battleId}/attack", $payload)->assertOk();

        $this->assertSame($first->json('data.battle.version'), $second->json('data.battle.version'));
        $ppAfter = (int) $first->json('data.battle.player.moves.0.pp.current');
        $this->assertSame($ppBefore - 1, $ppAfter);
        $this->assertSame($ppAfter, (int) $second->json('data.battle.player.moves.0.pp.current'));
    }

    private function seedCombatCatalog(): void
    {
        $fire = CreatureType::query()->firstOrCreate(['slug' => 'fire'], ['name' => 'Fire']);
        $water = CreatureType::query()->firstOrCreate(['slug' => 'water'], ['name' => 'Water']);
        $grass = CreatureType::query()->firstOrCreate(['slug' => 'grass'], ['name' => 'Grass']);

        foreach ([
            ['slug' => 'charmander', 'name' => 'Charmander', 'type' => $fire, 'external_id' => 4],
            ['slug' => 'squirtle', 'name' => 'Squirtle', 'type' => $water, 'external_id' => 7],
            ['slug' => 'bulbasaur', 'name' => 'Bulbasaur', 'type' => $grass, 'external_id' => 1],
            ['slug' => 'pikachu', 'name' => 'Pikachu', 'type' => $fire, 'external_id' => 25],
            ['slug' => 'eevee', 'name' => 'Eevee', 'type' => $fire, 'external_id' => 133],
        ] as $row) {
            $creature = Creature::factory()->create([
                'slug' => $row['slug'],
                'name' => $row['name'],
                'external_id' => $row['external_id'],
                'species_external_id' => $row['external_id'],
                'base_hp' => 45,
                'base_attack' => 52,
                'base_defense' => 43,
                'base_special_attack' => 60,
                'base_special_defense' => 50,
                'base_speed' => 65,
            ]);
            $creature->types()->sync([$row['type']->id => ['slot' => 1]]);
        }

        // Ensure opponent pool has at least these slugs present.
        app(SeedCombatDataService::class)->seed();
    }
}
