<?php

namespace Tests\Unit\Domain\Pvp;

use App\Domain\Pvp\Models\PvpRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guards against Postgres-incompatible latestOfMany(MAX(uuid)) on invitation.
 * Feature suites run on SQLite in-memory, so they would not catch this alone.
 */
class PvpRoomInvitationRelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_invitation_relation_sql_avoids_max_on_uuid_primary_key(): void
    {
        $room = new PvpRoom;
        $room->id = '01a218d3-57b8-7410-a537-1293022f044e';
        $room->exists = true;

        $sql = strtolower($room->invitation()->toSql());

        $this->assertStringNotContainsString('max(', $sql);
        $this->assertStringNotContainsString('latestofmany', $sql);
        $this->assertMatchesRegularExpression('/order by .*created_at/i', $sql);
    }

    public function test_invitation_eager_load_sql_avoids_max_on_uuid_primary_key(): void
    {
        $room = PvpRoom::query()->create([
            'host_user_id' => $this->makeHostUserId(),
            'status' => PvpRoom::STATUS_WAITING_FOR_GUEST,
            'version' => 1,
        ]);

        $queries = [];
        \DB::listen(static function ($query) use (&$queries): void {
            $queries[] = strtolower($query->sql);
        });

        $room->load('invitation');

        $invitationQueries = array_values(array_filter(
            $queries,
            static fn (string $sql): bool => str_contains($sql, 'pvp_invitations'),
        ));

        $this->assertNotEmpty($invitationQueries);
        foreach ($invitationQueries as $sql) {
            $this->assertStringNotContainsString(
                'max(',
                $sql,
                'Eager-loading invitation must not emit MAX(...) — Postgres rejects MAX(uuid).',
            );
        }
    }

    private function makeHostUserId(): int
    {
        return (int) \App\Models\User::factory()->withPlayer(
            ['handle' => 'relhost'.substr(uniqid(), -4)],
            ['level' => 6, 'total_xp' => 1500],
        )->create()->id;
    }
}
