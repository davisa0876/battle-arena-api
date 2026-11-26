<?php

namespace Tests\Feature\Pvp;

use App\Application\Battles\SeedCombatDataService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PvpBattleSecurityTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCombatCatalog();
        $this->fakeRedisPublish();
    }

    public function test_outsider_cannot_select_or_view_battle(): void
    {
        [$host, $guest, $roomId, $hostOwned, $roomVersion] = $this->readyLobbyWithSelections();
        $outsider = $this->makePlayer(['handle' => 'stranger'], [], ['level' => 6, 'total_xp' => 1500]);

        $this->withHeaders($this->asUser($outsider) + ['Idempotency-Key' => (string) Str::uuid()])
            ->putJson("/api/pvp/rooms/{$roomId}/selection", [
                'owned_creature_id' => $hostOwned->id,
                'expected_room_version' => $roomVersion,
            ])
            ->assertNotFound();

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion])
            ->assertOk();
        $ready = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion + 1])
            ->assertOk();
        $battleId = $ready->json('data.battle.id');

        $this->withHeaders($this->asUser($outsider))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertNotFound();
    }

    public function test_cannot_select_unowned_creature(): void
    {
        $host = $this->makePlayer(['handle' => 'hostown'], [], ['level' => 6, 'total_xp' => 1500]);
        $guest = $this->makePlayer(['handle' => 'guestown'], [], ['level' => 6, 'total_xp' => 1500]);
        $creature = Creature::query()->where('slug', 'charmander')->firstOrFail();
        $guestOwned = OwnedCreature::factory()->create([
            'user_id' => $guest->id,
            'creature_id' => $creature->id,
            'level' => 5,
            'is_active' => true,
        ]);

        $created = $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestown'])
            ->assertCreated();
        $roomId = $created->json('data.room.id');
        $inviteId = $created->json('data.invitation.id');
        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/invitations/{$inviteId}/accept")
            ->assertOk();

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->putJson("/api/pvp/rooms/{$roomId}/selection", [
                'owned_creature_id' => $guestOwned->id,
                'expected_room_version' => 2,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PVP_CREATURE_NOT_OWNED');
    }

    public function test_out_of_turn_attack_and_stale_version_rejected(): void
    {
        [$host, $guest, $roomId, , $roomVersion] = $this->readyLobbyWithSelections();

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion])
            ->assertOk();
        $ready = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion + 1])
            ->assertOk();
        $battleId = $ready->json('data.battle.id');

        $state = $this->withHeaders($this->asUser($host))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk();
        $attackerId = (int) $state->json('data.current_attacker_user_id');
        $defender = $attackerId === (int) $host->id ? $guest : $host;
        $version = (int) $state->json('data.version');

        $this->withHeaders($this->asUser($defender) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/battles/{$battleId}/attack", [
                'basic_strike' => true,
                'expected_version' => $version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_WRONG_TURN');

        $attacker = $attackerId === (int) $host->id ? $host : $guest;
        $this->withHeaders($this->asUser($attacker) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/battles/{$battleId}/attack", [
                'basic_strike' => true,
                'expected_version' => $version + 99,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_VERSION_CONFLICT');
    }

    public function test_attack_idempotency_replays_without_double_spend(): void
    {
        [$host, $guest, $roomId, , $roomVersion] = $this->readyLobbyWithSelections();

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion])
            ->assertOk();
        $ready = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion + 1])
            ->assertOk();
        $battleId = $ready->json('data.battle.id');

        $state = $this->withHeaders($this->asUser($host))->getJson("/api/pvp/battles/{$battleId}")->assertOk();
        $attackerId = (int) $state->json('data.current_attacker_user_id');
        $attacker = $attackerId === (int) $host->id ? $host : $guest;
        $version = (int) $state->json('data.version');
        $attackerState = $this->withHeaders($this->asUser($attacker))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk();
        $moves = collect($attackerState->json('data.me.moves') ?? []);
        $usable = $moves->firstWhere('can_use', true);
        $this->assertNotNull($usable);

        $payload = ['move_id' => (int) $usable['id'], 'expected_version' => $version];
        $key = 'idem-attack-once';

        $first = $this->withHeaders($this->asUser($attacker) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/battles/{$battleId}/attack", $payload)
            ->assertOk();
        $second = $this->withHeaders($this->asUser($attacker) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/battles/{$battleId}/attack", $payload)
            ->assertOk();

        $this->assertSame($first->json('data.version'), $second->json('data.version'));
        $this->assertSame('awaiting_defense', $first->json('data.phase'));
        $this->assertSame(1, PvpBattle::query()->whereKey($battleId)->count());
    }

    public function test_concurrent_ready_creates_exactly_one_battle(): void
    {
        [$host, $guest, $roomId, , $roomVersion] = $this->readyLobbyWithSelections();

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion])
            ->assertOk();

        // Same guest ready key replayed must not duplicate battles.
        $key = 'ready-once-guest';
        $first = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion + 1])
            ->assertOk();
        $second = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion + 1])
            ->assertOk();

        $this->assertSame($first->json('data.battle.id'), $second->json('data.battle.id'));
        $this->assertSame(1, PvpBattle::query()->where('room_id', $roomId)->count());
        $this->assertSame(PvpRoom::STATUS_IN_BATTLE, PvpRoom::query()->find($roomId)?->status);
    }

    public function test_opponent_moves_hidden_from_viewer(): void
    {
        [$host, $guest, $roomId, , $roomVersion] = $this->readyLobbyWithSelections();
        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion])
            ->assertOk();
        $ready = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $roomVersion + 1])
            ->assertOk();
        $battleId = $ready->json('data.battle.id');

        $state = $this->withHeaders($this->asUser($host))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk()
            ->json('data');

        $this->assertIsArray($state['me']['moves'] ?? null);
        $this->assertArrayNotHasKey('moves', $state['opponent'] ?? []);
        $this->assertArrayNotHasKey('rng_seed', $state);
        $this->assertArrayNotHasKey('rng_cursor', $state);
    }

    /**
     * @return array{0:\App\Models\User,1:\App\Models\User,2:string,3:OwnedCreature,4:int}
     */
    private function readyLobbyWithSelections(): array
    {
        $guestHandle = 'guestsec'.Str::lower(Str::random(4));
        $host = $this->makePlayer(['handle' => 'hostsec'.Str::lower(Str::random(4))], [], ['level' => 6, 'total_xp' => 1500]);
        $guest = $this->makePlayer(['handle' => $guestHandle], [], ['level' => 6, 'total_xp' => 1500]);
        $creature = Creature::query()->where('slug', 'charmander')->firstOrFail();
        $hostOwned = OwnedCreature::factory()->create([
            'user_id' => $host->id,
            'creature_id' => $creature->id,
            'level' => 5,
            'is_active' => true,
        ]);
        $guestOwned = OwnedCreature::factory()->create([
            'user_id' => $guest->id,
            'creature_id' => $creature->id,
            'level' => 5,
            'is_active' => true,
        ]);

        $created = $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/pvp/rooms', ['invitee_handle' => $guestHandle])
            ->assertCreated();
        $roomId = $created->json('data.room.id');
        $inviteId = $created->json('data.invitation.id');
        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/invitations/{$inviteId}/accept")
            ->assertOk();

        $version = (int) $this->withHeaders($this->asUser($host))
            ->getJson("/api/pvp/rooms/{$roomId}")
            ->assertOk()
            ->json('data.version');

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->putJson("/api/pvp/rooms/{$roomId}/selection", [
                'owned_creature_id' => $hostOwned->id,
                'expected_room_version' => $version,
            ])->assertOk();
        $version++;
        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->putJson("/api/pvp/rooms/{$roomId}/selection", [
                'owned_creature_id' => $guestOwned->id,
                'expected_room_version' => $version,
            ])->assertOk();

        return [$host, $guest, $roomId, $hostOwned, $version + 1];
    }

    /**
     * @return array<string, string>
     */
    private function asUser(\App\Models\User $user, array $headers = []): array
    {
        $this->app['auth']->forgetGuards();

        return array_merge($this->bearerFor($user), $headers);
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

        app(SeedCombatDataService::class)->seed();
    }

    private function fakeRedisPublish(): void
    {
        $client = new class
        {
            public function set(string $key, string $value, string $ex, int $ttl, string $nx): string
            {
                return 'OK';
            }

            public function publish(string $channel, string $message): int
            {
                return 1;
            }
        };

        $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('client')->andReturn($client);
        $factory = Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->andReturn($connection);
        $this->app->instance('redis', $factory);
        $this->app->instance(\Illuminate\Contracts\Redis\Factory::class, $factory);
    }
}
