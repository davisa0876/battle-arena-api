<?php

namespace Tests\Feature\Pvp;

use App\Application\Battles\SeedCombatDataService;
use App\Application\Pvp\PvpActionDeadlineService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Progression\Models\PlayerXpEvent;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpBattleReward;
use App\Domain\Pvp\Models\PvpRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PvpForfeitAndTimeoutTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedCombatCatalog();
        $this->fakeRedisPublish();
    }

    public function test_participant_can_forfeit_with_zero_xp_and_leave_stays_blocked(): void
    {
        [$host, $guest, $battleId] = $this->startActiveBattle();

        $hostXpBefore = (int) $host->fresh()->progress->total_xp;
        $guestXpBefore = (int) $guest->fresh()->progress->total_xp;

        $state = $this->withHeaders($this->asUser($host))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk();
        $version = (int) $state->json('data.version');
        $this->assertContains('forfeit', $state->json('data.allowed_actions'));
        $this->assertNotNull($state->json('data.action_deadline_at'));

        $roomId = $state->json('data.room_id');
        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/rooms/{$roomId}/leave")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_ALREADY_IN_BATTLE');

        $key = (string) Str::uuid();
        $forfeit = $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/battles/{$battleId}/forfeit", ['expected_version' => $version])
            ->assertOk();

        $this->assertSame('forfeit', $forfeit->json('data.battle.outcome.end_reason'));
        $this->assertSame((int) $guest->id, (int) $forfeit->json('data.battle.outcome.winner_user_id'));
        $this->assertSame((int) $host->id, (int) $forfeit->json('data.battle.outcome.loser_user_id'));
        $this->assertSame((int) $host->id, (int) $forfeit->json('data.battle.outcome.forfeiting_user_id'));

        $rewards = collect($forfeit->json('data.rewards') ?? $forfeit->json('data.battle.rewards') ?? []);
        $this->assertCount(2, $rewards);
        $this->assertTrue($rewards->every(fn ($r) => (int) $r['xp_awarded'] === 0));

        $this->assertSame($hostXpBefore, (int) $host->fresh()->progress->total_xp);
        $this->assertSame($guestXpBefore, (int) $guest->fresh()->progress->total_xp);
        $this->assertSame(0, PlayerXpEvent::query()->where('source_reference', $battleId)->count());
        $this->assertSame(2, PvpBattleReward::query()->where('battle_id', $battleId)->count());
        $this->assertSame(PvpRoom::STATUS_CLOSED, PvpRoom::query()->find($roomId)?->status);

        // Idempotent replay
        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/battles/{$battleId}/forfeit", ['expected_version' => $version])
            ->assertOk();

        // Same key different body
        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => $key])
            ->postJson("/api/pvp/battles/{$battleId}/forfeit", ['expected_version' => $version + 99])
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');

        // Guest action after forfeit
        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/battles/{$battleId}/attack", [
                'basic_strike' => true,
                'expected_version' => $version,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_ALREADY_FINISHED');

        // Outsider
        $outsider = $this->makePlayer(['handle' => 'outsider'], [], ['level' => 6, 'total_xp' => 1500]);
        $this->withHeaders($this->asUser($outsider))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertStatus(404);
    }

    public function test_forfeit_during_defense_does_not_apply_pending_attack(): void
    {
        [$host, $guest, $battleId] = $this->startActiveBattle();

        $state = $this->withHeaders($this->asUser($host))->getJson("/api/pvp/battles/{$battleId}")->assertOk();
        $attackerId = (int) $state->json('data.current_attacker_user_id');
        $defenderId = (int) $state->json('data.current_defender_user_id');
        $attacker = (int) $host->id === $attackerId ? $host : $guest;
        $defender = (int) $host->id === $defenderId ? $host : $guest;

        $version = (int) $state->json('data.version');
        $moves = collect($this->withHeaders($this->asUser($attacker))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->json('data.me.moves') ?? []);
        $usable = $moves->firstWhere('can_use', true);
        $payload = $usable
            ? ['move_id' => (int) $usable['id'], 'expected_version' => $version]
            : ['basic_strike' => true, 'expected_version' => $version];

        $this->withHeaders($this->asUser($attacker) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/battles/{$battleId}/attack", $payload)
            ->assertOk();

        $defState = $this->withHeaders($this->asUser($defender))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk();
        $this->assertSame('awaiting_defense', $defState->json('data.phase'));
        $this->assertNotNull($defState->json('data.pending_attack'));
        $hpBefore = (int) $defState->json('data.me.hp.current');

        $this->withHeaders($this->asUser($defender) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/battles/{$battleId}/forfeit", [
                'expected_version' => (int) $defState->json('data.version'),
            ])
            ->assertOk()
            ->assertJsonPath('data.battle.outcome.end_reason', 'forfeit');

        $finished = $this->withHeaders($this->asUser($defender))
            ->getJson("/api/pvp/battles/{$battleId}")
            ->assertOk();
        $this->assertNull($finished->json('data.pending_attack'));
        $this->assertSame($hpBefore, (int) $finished->json('data.me.hp.current'));
        $this->assertSame('forfeit', $finished->json('data.outcome.end_reason'));
    }

    public function test_timeout_adjudication_when_enabled_awards_zero_xp(): void
    {
        config([
            'pvp.turn_timeout.enabled' => true,
            'pvp.turn_timeout.seconds' => 300,
        ]);

        [$host, $guest, $battleId] = $this->startActiveBattle();
        $battle = PvpBattle::query()->findOrFail($battleId);
        $this->assertNotNull($battle->action_deadline_at);

        $timedOutActor = (int) $battle->deadline_actor_user_id;
        $winnerExpected = $timedOutActor === (int) $host->id ? (int) $guest->id : (int) $host->id;

        Carbon::setTestNow($battle->action_deadline_at->copy()->addSecond());

        $result = app(PvpActionDeadlineService::class)->adjudicateDueBatch(10);
        $this->assertSame(1, $result['timed_out']);

        $battle->refresh();
        $this->assertTrue($battle->isFinished());
        $this->assertSame(PvpBattle::END_REASON_TIMEOUT, $battle->end_reason);
        $this->assertSame($timedOutActor, (int) $battle->timed_out_user_id);
        $this->assertSame($winnerExpected, (int) $battle->winner_user_id);
        $this->assertSame(0, PvpBattleReward::query()->where('battle_id', $battleId)->sum('xp_awarded'));
        $this->assertSame(0, PlayerXpEvent::query()->where('source_reference', $battleId)->count());

        // Disabled adjudicator is a no-op even if overdue
        config(['pvp.turn_timeout.enabled' => false]);
        $noop = app(PvpActionDeadlineService::class)->adjudicateDueBatch(10);
        $this->assertSame(0, $noop['processed']);

        Carbon::setTestNow();
    }

    public function test_stale_version_forfeit_returns_conflict(): void
    {
        [$host, , $battleId] = $this->startActiveBattle();
        $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/battles/{$battleId}/forfeit", ['expected_version' => 999])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_VERSION_CONFLICT');
    }

    /**
     * @return array{0:\App\Models\User,1:\App\Models\User,2:string}
     */
    private function startActiveBattle(): array
    {
        $host = $this->makePlayer(['handle' => 'hostff'], [], ['level' => 6, 'total_xp' => 1500]);
        $guest = $this->makePlayer(['handle' => 'guestff'], [], ['level' => 6, 'total_xp' => 1500]);
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
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestff'])
            ->assertCreated();
        $roomId = $created->json('data.room.id') ?? $created->json('data.id');
        $inviteId = $created->json('data.invitation.id');

        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/invitations/{$inviteId}/accept")
            ->assertOk();

        $version = (int) $this->withHeaders($this->asUser($host))->getJson("/api/pvp/rooms/{$roomId}")->json('data.version');

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

        return [$host, $guest, $battleId];
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
