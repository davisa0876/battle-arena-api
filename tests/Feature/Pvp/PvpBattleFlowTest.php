<?php

namespace Tests\Feature\Pvp;

use App\Application\Battles\SeedCombatDataService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Progression\Models\PlayerXpEvent;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PvpBattleFlowTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCombatCatalog();
        $this->fakeRedisPublish();
    }

    public function test_two_trainers_can_select_ready_fight_and_earn_xp_once(): void
    {
        $host = $this->makePlayer(['handle' => 'hostsix'], [], ['level' => 6, 'total_xp' => 1500]);
        $guest = $this->makePlayer(['handle' => 'guestsix'], [], ['level' => 6, 'total_xp' => 1500]);
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
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestsix']);
        $created->assertCreated();
        $roomId = $created->json('data.room.id') ?? $created->json('data.id');
        $inviteId = $created->json('data.invitation.id');

        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/invitations/{$inviteId}/accept")
            ->assertOk();

        $room = $this->withHeaders($this->asUser($host))->getJson("/api/pvp/rooms/{$roomId}")->assertOk();
        $version = (int) ($room->json('data.version') ?? $room->json('data.room.version') ?? 1);

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

        $version++;
        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $version])
            ->assertOk();

        $version++;
        $ready = $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/ready", ['expected_room_version' => $version])
            ->assertOk();

        $battleId = $ready->json('data.battle.id') ?? $ready->json('data.battle_id');
        $this->assertNotEmpty($battleId);
        $this->assertSame(1, PvpBattle::query()->where('room_id', $roomId)->count());
        $this->assertSame(PvpRoom::STATUS_IN_BATTLE, PvpRoom::query()->find($roomId)?->status);

        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/leave")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_ALREADY_IN_BATTLE');

        $guard = 0;
        $finished = false;
        while ($guard++ < 100 && ! $finished) {
            foreach ([$host, $guest] as $actor) {
                $state = $this->withHeaders($this->asUser($actor))
                    ->getJson("/api/pvp/battles/{$battleId}")
                    ->assertOk();
                if ($state->json('data.phase') === 'finished' || $state->json('data.status') === 'finished') {
                    $finished = true;
                    break 2;
                }

                $phase = $state->json('data.phase');
                $version = (int) $state->json('data.version');
                $attacker = (int) $state->json('data.current_attacker_user_id');
                $defender = (int) $state->json('data.current_defender_user_id');

                if ($phase === 'awaiting_attack' && $attacker === (int) $actor->id) {
                    $moves = collect($state->json('data.me.moves') ?? []);
                    $usable = $moves->firstWhere('can_use', true);
                    $payload = $usable
                        ? ['move_id' => (int) $usable['id'], 'expected_version' => $version]
                        : ['recover' => true, 'expected_version' => $version];
                    $this->withHeaders($this->asUser($actor) + ['Idempotency-Key' => 'atk-'.$guard.'-'.$actor->id])
                        ->postJson("/api/pvp/battles/{$battleId}/attack", $payload)
                        ->assertOk();
                    break;
                }

                if ($phase === 'awaiting_defense' && $defender === (int) $actor->id) {
                    $this->withHeaders($this->asUser($actor) + ['Idempotency-Key' => 'def-'.$guard.'-'.$actor->id])
                        ->postJson("/api/pvp/battles/{$battleId}/defend", [
                            'defense' => 'endure',
                            'expected_version' => $version,
                        ])->assertOk();
                    break;
                }
            }
        }

        $this->assertTrue($finished, 'Battle should finish by KO within turn cap.');

        $final = $this->withHeaders($this->asUser($host))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk();
        $winnerId = (int) $final->json('data.outcome.winner_user_id');
        $loserId = (int) $final->json('data.outcome.loser_user_id');
        $this->assertNotSame(0, $winnerId);
        $this->assertNotSame($winnerId, $loserId);

        $winXp = PlayerXpEvent::query()->where('user_id', $winnerId)->where('source_type', 'battle_pvp')->sum('amount');
        $lossXp = PlayerXpEvent::query()->where('user_id', $loserId)->where('source_type', 'battle_pvp')->sum('amount');
        $this->assertSame(20, (int) $winXp);
        $this->assertSame(10, (int) $lossXp);
        $this->assertSame(PvpRoom::STATUS_CLOSED, PvpRoom::query()->find($roomId)?->status);
        $this->assertSame(PvpRoom::CLOSED_BATTLE_COMPLETED, PvpRoom::query()->find($roomId)?->closed_reason);
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
