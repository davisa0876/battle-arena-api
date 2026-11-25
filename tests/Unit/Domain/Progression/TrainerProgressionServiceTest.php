<?php

namespace Tests\Unit\Domain\Progression;

use App\Domain\Progression\TrainerProgressionService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TrainerProgressionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TrainerProgressionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(TrainerProgressionService::class);
    }

    public function test_xp_to_advance_is_arithmetic(): void
    {
        $this->assertSame(100, $this->service->xpToAdvance(1));
        $this->assertSame(200, $this->service->xpToAdvance(2));
        $this->assertSame(300, $this->service->xpToAdvance(3));
        $this->assertSame(400, $this->service->xpToAdvance(4));
        $this->assertSame(500, $this->service->xpToAdvance(5));
    }

    public function test_total_xp_for_level_closed_form(): void
    {
        $this->assertSame(0, $this->service->totalXpForLevel(1));
        $this->assertSame(100, $this->service->totalXpForLevel(2));
        $this->assertSame(300, $this->service->totalXpForLevel(3));
        $this->assertSame(600, $this->service->totalXpForLevel(4));
        $this->assertSame(1000, $this->service->totalXpForLevel(5));
        $this->assertSame(1500, $this->service->totalXpForLevel(6));
        $this->assertSame(4500, $this->service->totalXpForLevel(10));
        $this->assertSame(19000, $this->service->totalXpForLevel(20));
    }

    #[DataProvider('levelForXpProvider')]
    public function test_level_for_total_xp(int $xp, int $expectedLevel): void
    {
        $this->assertSame($expectedLevel, $this->service->levelForTotalXp($xp));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function levelForXpProvider(): array
    {
        return [
            '0' => [0, 1],
            '99' => [99, 1],
            '100' => [100, 2],
            '299' => [299, 2],
            '300' => [300, 3],
            '600' => [600, 4],
            '1000' => [1000, 5],
            '1500' => [1500, 6],
            '4500' => [4500, 10],
            '19000' => [19000, 20],
        ];
    }

    public function test_single_and_multiple_level_ups(): void
    {
        $user = User::factory()->withPlayer()->create();

        $single = $this->service->awardXp($user, 100, 'test', 'a', 'xp-single');
        $this->assertSame(2, $single->progress->level);
        $this->assertSame(100, $single->progress->total_xp);
        $this->assertSame(1, $single->levelsGained);

        $multi = $this->service->awardXp($user, 200, 'test', 'b', 'xp-multi');
        $this->assertSame(3, $multi->progress->level);
        $this->assertSame(300, $multi->progress->total_xp);
        $this->assertSame(1, $multi->levelsGained);
    }

    public function test_duplicate_idempotency_key_does_not_double_award(): void
    {
        $user = User::factory()->withPlayer()->create();

        $first = $this->service->awardXp($user, 50, 'test', null, 'same-key');
        $second = $this->service->awardXp($user, 50, 'test', null, 'same-key');

        $this->assertFalse($first->wasDuplicate);
        $this->assertTrue($second->wasDuplicate);
        $this->assertSame(50, (int) $user->fresh()->progress->total_xp);
        $this->assertDatabaseCount('player_xp_events', 1);
    }

    public function test_negative_xp_is_rejected(): void
    {
        $user = User::factory()->withPlayer()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->awardXp($user, 0, 'test', null, 'bad');
    }

    public function test_snapshot_for_fresh_trainer(): void
    {
        $user = User::factory()->withPlayer()->create();
        $snapshot = $this->service->snapshot($user->progress);

        $this->assertSame(1, $snapshot->level);
        $this->assertSame(0, $snapshot->totalXp);
        $this->assertSame(0, $snapshot->xpIntoLevel);
        $this->assertSame(100, $snapshot->xpForNextLevel);
        $this->assertSame(0.0, $snapshot->progressPercent);
    }
}
