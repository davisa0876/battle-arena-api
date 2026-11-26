<?php

namespace Tests\Feature\Pvp;

use App\Domain\Pvp\Models\PvpInvitation;
use App\Domain\Pvp\Models\PvpRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Mockery;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PvpPrivateLobbyTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    private function level6(array $profile = [], array $user = []): \App\Models\User
    {
        return $this->makePlayer($profile, $user, ['level' => 6, 'total_xp' => 1500]);
    }

    private function level5(array $profile = [], array $user = []): \App\Models\User
    {
        return $this->makePlayer($profile, $user, ['level' => 5, 'total_xp' => 1000]);
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
        $factory->shouldReceive('connection')->with('ws_tickets')->andReturn($connection);
        $this->app->instance('redis', $factory);
        $this->app->instance(\Illuminate\Contracts\Redis\Factory::class, $factory);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function asUser(\App\Models\User $user, array $headers = []): array
    {
        $this->app['auth']->forgetGuards();

        return $headers + $this->bearerFor($user);
    }

    public function test_unauthenticated_cannot_create_room(): void
    {
        $this->postJson('/api/pvp/rooms', ['invitee_handle' => 'ash'])->assertUnauthorized();
    }

    public function test_level_5_cannot_create_or_accept(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level5(['handle' => 'lowlevel']);
        $guest = $this->level6(['handle' => 'readyone']);

        $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'readyone'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PVP_NOT_ELIGIBLE');

        $eligibleHost = $this->level6(['handle' => 'hostsix']);
        $this->level5(['handle' => 'guestfive']);

        $this->withHeaders($this->asUser($eligibleHost))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestfive'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PVP_INVITEE_UNAVAILABLE');

        $this->assertDatabaseCount('pvp_rooms', 0);
    }

    public function test_host_can_invite_exact_handle_and_guest_accepts(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'HostOne']);
        $guest = $this->level6(['handle' => 'GuestTwo']);

        $created = $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/pvp/rooms', [
                'invitee_handle' => 'guesttwo',
            ])
            ->assertCreated()
            ->assertJsonPath('data.room.status', 'waiting_for_guest')
            ->assertJsonPath('data.invitation.status', 'pending');

        $invitationId = $created->json('data.invitation.id');
        $roomId = $created->json('data.room.id');

        $this->withHeaders($this->asUser($guest))
            ->getJson('/api/pvp/invitations?box=received')
            ->assertOk()
            ->assertJsonPath('data.0.id', $invitationId);

        $this->withHeaders($this->asUser($guest) + ['Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/pvp/invitations/{$invitationId}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.id', $roomId);

        $this->assertDatabaseHas('pvp_room_members', [
            'room_id' => $roomId,
            'user_id' => $guest->id,
            'role' => 'guest',
        ]);

        $this->withHeaders($this->asUser($host))
            ->getJson("/api/pvp/rooms/{$roomId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $stranger = $this->level6(['handle' => 'stranger']);
        $this->withHeaders($this->asUser($stranger))
            ->getJson("/api/pvp/rooms/{$roomId}")
            ->assertNotFound();
    }

    public function test_self_invite_and_unknown_handle_are_rejected_safely(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'solo']);

        $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'solo'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PVP_SELF_INVITE');

        $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'missing_user'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'PVP_INVITEE_UNAVAILABLE');
    }

    public function test_decline_cancel_and_leave_close_lobby_without_xp_change(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'hosta']);
        $guest = $this->level6(['handle' => 'guestb']);
        $xpBefore = (int) $host->progress->total_xp;

        $created = $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestb'])
            ->assertCreated();
        $invitationId = $created->json('data.invitation.id');

        $this->withHeaders($this->asUser($guest))
            ->postJson("/api/pvp/invitations/{$invitationId}/decline")
            ->assertOk()
            ->assertJsonPath('data.room.status', 'closed');

        $host2 = $this->level6(['handle' => 'hostc']);
        $guest2 = $this->level6(['handle' => 'guestd']);
        $created2 = $this->withHeaders($this->asUser($host2))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestd'])
            ->assertCreated();
        $invite2 = $created2->json('data.invitation.id');
        $this->withHeaders($this->asUser($host2))
            ->postJson("/api/pvp/invitations/{$invite2}/cancel")
            ->assertOk()
            ->assertJsonPath('data.room.status', 'closed');

        $host3 = $this->level6(['handle' => 'hoste']);
        $guest3 = $this->level6(['handle' => 'guestf']);
        $created3 = $this->withHeaders($this->asUser($host3))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestf'])
            ->assertCreated();
        $invite3 = $created3->json('data.invitation.id');
        $room3 = $created3->json('data.room.id');
        $this->withHeaders($this->asUser($guest3))
            ->postJson("/api/pvp/invitations/{$invite3}/accept")
            ->assertOk();
        $this->withHeaders($this->asUser($host3))
            ->postJson("/api/pvp/rooms/{$room3}/leave")
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $host->progress->refresh();
        $this->assertSame($xpBefore, (int) $host->progress->total_xp);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'hostx']);
        $guest = $this->level6(['handle' => 'guesty']);

        $created = $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guesty'])
            ->assertCreated();
        $invitationId = $created->json('data.invitation.id');

        PvpInvitation::query()->whereKey($invitationId)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->withHeaders($this->asUser($guest))
            ->postJson("/api/pvp/invitations/{$invitationId}/accept")
            ->assertStatus(409)
            ->assertJsonPath('code', 'PVP_INVITE_EXPIRED');

        $this->assertSame(PvpRoom::STATUS_CLOSED, PvpRoom::query()->find($created->json('data.room.id'))?->status);
    }

    public function test_create_room_is_idempotent_with_same_key(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'hostidem']);
        $guest = $this->level6(['handle' => 'guestidem']);
        $key = (string) Str::uuid();

        $first = $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => $key])
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestidem'])
            ->assertCreated();

        $second = $this->withHeaders($this->asUser($host) + ['Idempotency-Key' => $key])
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestidem'])
            ->assertCreated();

        $this->assertSame($first->json('data.room.id'), $second->json('data.room.id'));
        $this->assertSame(1, PvpRoom::query()->count());
    }

    public function test_subscription_grant_requires_membership_and_sets_no_store(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'grantHost']);
        $guest = $this->level6(['handle' => 'grantGuest']);
        $stranger = $this->level6(['handle' => 'grantStranger']);

        $created = $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'grantguest'])
            ->assertCreated();
        $roomId = $created->json('data.room.id');
        $inviteId = $created->json('data.invitation.id');

        $this->withHeaders($this->asUser($guest))
            ->postJson("/api/pvp/invitations/{$inviteId}/accept")
            ->assertOk();

        $this->withHeaders($this->asUser($host))
            ->postJson("/api/pvp/rooms/{$roomId}/subscription-grants")
            ->assertCreated()
            ->assertHeaderContains('Cache-Control', 'no-store')
            ->assertJsonStructure(['data' => ['grant', 'expires_in', 'room_id']]);

        $this->withHeaders($this->asUser($stranger))
            ->postJson("/api/pvp/rooms/{$roomId}/subscription-grants")
            ->assertNotFound();
    }

    public function test_non_invitee_cannot_accept(): void
    {
        $this->fakeRedisPublish();
        $host = $this->level6(['handle' => 'hostz']);
        $guest = $this->level6(['handle' => 'guestz']);
        $other = $this->level6(['handle' => 'otherz']);

        $created = $this->withHeaders($this->asUser($host))
            ->postJson('/api/pvp/rooms', ['invitee_handle' => 'guestz'])
            ->assertCreated();
        $invitationId = $created->json('data.invitation.id');

        $this->withHeaders($this->asUser($other))
            ->postJson("/api/pvp/invitations/{$invitationId}/accept")
            ->assertForbidden();
    }
}
