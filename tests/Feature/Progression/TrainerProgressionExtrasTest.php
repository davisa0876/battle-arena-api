<?php

namespace Tests\Feature\Progression;

use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\TrainerProgressionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class TrainerProgressionExtrasTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    private TrainerProgressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(TrainerProgressionService::class);
    }

    public function test_cumulative_thresholds_follow_the_curve(): void
    {
        $this->assertSame(0, $this->service->totalXpForLevel(1));
        $this->assertSame(100, $this->service->totalXpForLevel(2));
        $this->assertSame(300, $this->service->totalXpForLevel(3));
        $this->assertSame(600, $this->service->totalXpForLevel(4));

        $this->assertSame(1, $this->service->levelForTotalXp(99));
        $this->assertSame(2, $this->service->levelForTotalXp(100));
        $this->assertSame(2, $this->service->levelForTotalXp(299));
        $this->assertSame(3, $this->service->levelForTotalXp(300));
    }

    public function test_formula_follows_configuration(): void
    {
        config(['game.progression.xp_increment_per_level' => 50]);

        $this->assertSame(50, $this->service->xpToAdvance(1));
        $this->assertSame(100, $this->service->xpToAdvance(2));
        $this->assertSame(150, $this->service->xpToAdvance(3));
        $this->assertSame(150, $this->service->totalXpForLevel(3)); // 50*2*3/2
    }

    public function test_invalid_levels_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->service->xpToAdvance(0);
    }

    public function test_snapshot_mid_level_and_progress_percent(): void
    {
        // Level 2 starts at 100 XP; span to level 3 is 200 XP.
        $user = $this->makePlayer(progress: ['total_xp' => 200, 'level' => 2]);

        $snapshot = $this->service->snapshot($user->progress);

        $this->assertSame(2, $snapshot->level);
        $this->assertSame(100, $snapshot->xpIntoLevel);
        $this->assertSame(200, $snapshot->xpForNextLevel);
        $this->assertSame(100, $snapshot->xpToNextLevel);
        $this->assertSame(50.0, $snapshot->progressPercent);
        $this->assertFalse($snapshot->isMaxLevel);
    }

    public function test_max_level_is_capped(): void
    {
        config(['game.progression.max_level' => 3]);
        $user = $this->makePlayer();

        $result = $this->service->awardXp($user, 10_000, 'test', null, 'big');
        $snapshot = $this->service->snapshot($result->progress);

        $this->assertSame(3, $result->progress->level);
        $this->assertSame(10_000, $result->progress->total_xp);
        $this->assertTrue($snapshot->isMaxLevel);
        $this->assertNull($snapshot->xpToNextLevel);
        $this->assertSame(100.0, $snapshot->progressPercent);
    }

    public function test_award_records_an_audit_event(): void
    {
        $user = $this->makePlayer();

        $this->service->awardXp($user, 120, 'quest', 'quest-7', 'quest-7:user-1');

        $this->assertDatabaseHas('player_xp_events', [
            'user_id' => $user->id,
            'amount' => 120,
            'source_type' => 'quest',
            'source_reference' => 'quest-7',
            'idempotency_key' => 'quest-7:user-1',
        ]);
        $this->assertSame(120, $user->fresh()->progress->total_xp);
        $this->assertSame(2, $user->fresh()->progress->level);
    }

    public function test_negative_and_blank_key_awards_are_rejected_without_side_effects(): void
    {
        $user = $this->makePlayer();

        foreach ([[-5, 'k1'], [0, 'k2'], [10, '  ']] as [$amount, $key]) {
            try {
                $this->service->awardXp($user, $amount, 'test', null, $key);
                $this->fail('Expected InvalidArgumentException');
            } catch (InvalidArgumentException) {
                // expected
            }
        }

        $this->assertDatabaseCount('player_xp_events', 0);
        $this->assertSame(0, $user->fresh()->progress->total_xp);
    }

    public function test_idempotency_keys_cannot_be_reused_by_another_trainer(): void
    {
        $a = $this->makePlayer();
        $b = $this->makePlayer();
        $this->service->awardXp($a, 10, 'test', null, 'shared');

        $this->expectException(InvalidArgumentException::class);
        $this->service->awardXp($b, 10, 'test', null, 'shared');
    }

    public function test_missing_progress_row_is_created_on_first_award(): void
    {
        $user = User::factory()->create();

        $result = $this->service->awardXp($user, 25, 'test', null, 'first');

        $this->assertSame(25, $result->progress->total_xp);
        $this->assertSame(1, PlayerProgress::query()->where('user_id', $user->id)->count());
    }

    public function test_there_is_no_public_xp_endpoint(): void
    {
        $user = $this->makePlayer();
        $headers = $this->bearerFor($user);

        foreach (['/api/me/xp', '/api/me/progress/award', '/api/xp'] as $path) {
            $this->withHeaders($headers)->postJson($path, ['amount' => 1000])->assertNotFound();
        }

        $this->assertSame(0, $user->fresh()->progress->total_xp);
    }
}
