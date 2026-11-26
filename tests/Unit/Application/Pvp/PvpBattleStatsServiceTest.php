<?php

namespace Tests\Unit\Application\Pvp;

use App\Application\Pvp\PvpBattleStatsService;
use App\Domain\Pvp\Models\PvpBattle;
use App\Domain\Pvp\Models\PvpRoom;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PvpBattleStatsServiceTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_counts_wins_losses_and_self_forfeits(): void
    {
        $host = $this->makePlayer(['handle' => 'statshost'], [], ['level' => 6, 'total_xp' => 1500]);
        $guest = $this->makePlayer(['handle' => 'statsguest'], [], ['level' => 6, 'total_xp' => 1500]);

        $this->makeFinishedBattle($host->id, $guest->id, winner: $host->id, loser: $guest->id, endReason: PvpBattle::END_REASON_COMBAT);
        $this->makeFinishedBattle($host->id, $guest->id, winner: $guest->id, loser: $host->id, endReason: PvpBattle::END_REASON_TIMEOUT, timedOut: $host->id);
        $this->makeFinishedBattle(
            $host->id,
            $guest->id,
            winner: $guest->id,
            loser: $host->id,
            endReason: PvpBattle::END_REASON_FORFEIT,
            forfeiting: $host->id,
        );

        $stats = app(PvpBattleStatsService::class)->forUser($host);

        $this->assertSame(1, $stats['wins']);
        $this->assertSame(1, $stats['losses']);
        $this->assertSame(1, $stats['forfeits']);
    }

    private function makeFinishedBattle(
        int $hostId,
        int $guestId,
        int $winner,
        int $loser,
        string $endReason,
        ?int $forfeiting = null,
        ?int $timedOut = null,
    ): void {
        $room = PvpRoom::query()->create([
            'id' => (string) Str::uuid(),
            'host_user_id' => $hostId,
            'status' => PvpRoom::STATUS_CLOSED,
            'version' => 1,
            'closed_reason' => PvpRoom::CLOSED_BATTLE_COMPLETED,
            'closed_at' => now(),
        ]);

        PvpBattle::query()->create([
            'id' => (string) Str::uuid(),
            'room_id' => $room->id,
            'status' => PvpBattle::STATUS_FINISHED,
            'phase' => PvpBattle::PHASE_FINISHED,
            'turn_number' => 1,
            'version' => 2,
            'host_user_id' => $hostId,
            'guest_user_id' => $guestId,
            'current_attacker_user_id' => $hostId,
            'current_defender_user_id' => $guestId,
            'rng_seed' => (string) Str::uuid(),
            'rng_cursor' => 0,
            'rules_version' => 'test',
            'rules_snapshot' => [],
            'reward_snapshot' => [],
            'winner_user_id' => $winner,
            'loser_user_id' => $loser,
            'forfeiting_user_id' => $forfeiting,
            'timed_out_user_id' => $timedOut,
            'end_reason' => $endReason,
            'rewards_processed' => true,
            'last_event_sequence' => 1,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);
    }
}
