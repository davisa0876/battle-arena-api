<?php

namespace Tests\Feature\Pvp;

use App\Domain\Pvp\Models\PvpRoom;
use App\Domain\Pvp\Models\PvpRoomMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $low = $this->makePlayer(['handle' => 'toolow'], [], ['level' => 5, 'total_xp' => 1000]);
        $busy = $this->level6(['handle' => 'busyopp']);
        $offline = $this->level6(['handle' => 'offlineopp']);

        $room = PvpRoom::query()->create([
            'host_user_id' => $busy->id,
            'status' => PvpRoom::STATUS_WAITING_FOR_GUEST,
            'version' => 1,
        ]);
        PvpRoomMember::query()->create([
            'room_id' => $room->id,
            'user_id' => $busy->id,
            'role' => PvpRoomMember::ROLE_HOST,
            'joined_at' => now(),
        ]);

        $this->fakePresence([$viewer->id, $online->id, $low->id, $busy->id, $offline->id + 999]);

        $this->withHeaders($this->bearerFor($viewer))
            ->getJson('/api/pvp/online')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.handle', 'onlineopp')
            ->assertJsonPath('data.0.level', 6);
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
}
