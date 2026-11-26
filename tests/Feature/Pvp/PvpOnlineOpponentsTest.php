<?php

namespace Tests\Feature\Pvp;

use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PvpOnlineOpponentsTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    private function level6(array $profile = []): \App\Models\User
    {
        return $this->makePlayer($profile, [], ['level' => 6, 'total_xp' => 1500]);
    }

    /**
     * @param  list<int|string>  $onlineIds
     */
    private function fakePresence(array $onlineIds): void
    {
        $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('smembers')
            ->with('battle-arena:presence:online')
            ->andReturn(array_map(static fn ($id) => (string) $id, $onlineIds));

        $factory = Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->andReturn($connection);
        $this->app->instance('redis', $factory);
        $this->app->instance(\Illuminate\Contracts\Redis\Factory::class, $factory);
    }

    public function test_unauthenticated_cannot_list_online(): void
    {
        $this->getJson('/api/pvp/online')->assertUnauthorized();
    }

    public function test_lists_online_eligible_opponents_and_excludes_self_busy_and_underlevel(): void
    {
        $viewer = $this->level6(['handle' => 'viewerone']);
        $online = $this->level6(['handle' => 'onlineopp']);
        $waiting = $this->level6(['handle' => 'waitinghost']);
        $low = $this->makePlayer(['handle' => 'toolow'], [], ['level' => 5, 'total_xp' => 1000]);
        $busy = $this->level6(['handle' => 'busyopp']);
        $offline = $this->level6(['handle' => 'offlineopp']);

        // Pending outbound invite must NOT hide the host from Find online.
        $waitingRoom = PvpRoom::query()->create([
            'host_user_id' => $waiting->id,
            'status' => PvpRoom::STATUS_WAITING_FOR_GUEST,
            'version' => 1,
        ]);
        PvpRoomMember::query()->create([
            'room_id' => $waitingRoom->id,
            'user_id' => $waiting->id,
            'role' => PvpRoomMember::ROLE_HOST,
            'joined_at' => now(),
        ]);

        $room = PvpRoom::query()->create([
            'host_user_id' => $busy->id,
            'status' => PvpRoom::STATUS_READY,
            'version' => 1,
        ]);
        PvpRoomMember::query()->create([
            'room_id' => $room->id,
            'user_id' => $busy->id,
            'role' => PvpRoomMember::ROLE_HOST,
            'joined_at' => now(),
        ]);

        $this->fakePresence([$viewer->id, $online->id, $waiting->id, $low->id, $busy->id, $offline->id + 999]);

        $response = $this->withHeaders($this->bearerFor($viewer))
            ->getJson('/api/pvp/online')
            ->assertOk();

        $handles = collect($response->json('data'))->pluck('handle')->all();
        $this->assertEqualsCanonicalizing(['onlineopp', 'waitinghost'], $handles);
        $this->assertNotContains('busyopp', $handles);
        $this->assertNotContains('toolow', $handles);
    }

    public function test_empty_when_redis_unavailable(): void
    {
        $viewer = $this->level6(['handle' => 'viewerx']);

        $connection = Mockery::mock(\Illuminate\Redis\Connections\Connection::class);
        $connection->shouldReceive('smembers')->andThrow(new \RuntimeException('redis down'));
        $factory = Mockery::mock(\Illuminate\Contracts\Redis\Factory::class);
        $factory->shouldReceive('connection')->andReturn($connection);
        $this->app->instance('redis', $factory);
        $this->app->instance(\Illuminate\Contracts\Redis\Factory::class, $factory);

        $this->withHeaders($this->bearerFor($viewer))
            ->getJson('/api/pvp/online')
            ->assertOk()
            ->assertJsonPath('data', []);
    }

    public function test_orphan_in_battle_room_without_active_battle_is_not_busy(): void
    {
        $viewer = $this->level6(['handle' => 'viewerorb']);
        $stuck = $this->level6(['handle' => 'stuckopp']);

        $room = PvpRoom::query()->create([
            'host_user_id' => $stuck->id,
            'status' => PvpRoom::STATUS_IN_BATTLE,
            'version' => 2,
        ]);
        PvpRoomMember::query()->create([
            'room_id' => $room->id,
            'user_id' => $stuck->id,
            'role' => PvpRoomMember::ROLE_HOST,
            'joined_at' => now(),
        ]);
        PvpBattle::query()->create([
            'id' => (string) Str::uuid(),
            'room_id' => $room->id,
            'status' => PvpBattle::STATUS_FINISHED,
            'phase' => PvpBattle::PHASE_FINISHED,
            'turn_number' => 1,
            'version' => 2,
            'host_user_id' => $stuck->id,
            'guest_user_id' => $viewer->id,
            'current_attacker_user_id' => $stuck->id,
            'current_defender_user_id' => $viewer->id,
            'rng_seed' => (string) Str::uuid(),
            'rng_cursor' => 0,
            'rules_version' => 'test',
            'rules_snapshot' => [],
            'reward_snapshot' => [],
            'winner_user_id' => $viewer->id,
            'loser_user_id' => $stuck->id,
            'end_reason' => PvpBattle::END_REASON_COMBAT,
            'rewards_processed' => true,
            'last_event_sequence' => 1,
            'started_at' => now()->subMinutes(10),
            'finished_at' => now()->subMinutes(5),
        ]);

        $this->fakePresence([$viewer->id, $stuck->id]);

        $this->withHeaders($this->bearerFor($viewer))
            ->getJson('/api/pvp/online')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.handle', 'stuckopp');

        $this->assertSame(PvpRoom::STATUS_CLOSED, PvpRoom::query()->find($room->id)?->status);
    }

    public function test_active_in_battle_opponent_stays_hidden(): void
    {
        $viewer = $this->level6(['handle' => 'vieweract']);
        $fighting = $this->level6(['handle' => 'fightopp']);

        $room = PvpRoom::query()->create([
            'host_user_id' => $fighting->id,
            'status' => PvpRoom::STATUS_IN_BATTLE,
            'version' => 2,
        ]);
        PvpRoomMember::query()->create([
            'room_id' => $room->id,
            'user_id' => $fighting->id,
            'role' => PvpRoomMember::ROLE_HOST,
            'joined_at' => now(),
        ]);
        PvpBattle::query()->create([
            'id' => (string) Str::uuid(),
            'room_id' => $room->id,
            'status' => PvpBattle::STATUS_ACTIVE,
            'phase' => PvpBattle::PHASE_AWAITING_ATTACK,
            'turn_number' => 1,
            'version' => 1,
            'host_user_id' => $fighting->id,
            'guest_user_id' => $viewer->id,
            'current_attacker_user_id' => $fighting->id,
            'current_defender_user_id' => $viewer->id,
            'rng_seed' => (string) Str::uuid(),
            'rng_cursor' => 0,
            'rules_version' => 'test',
            'rules_snapshot' => [],
            'reward_snapshot' => [],
            'last_event_sequence' => 0,
            'started_at' => now(),
        ]);

        $this->fakePresence([$viewer->id, $fighting->id]);

        $this->withHeaders($this->bearerFor($viewer))
            ->getJson('/api/pvp/online')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
