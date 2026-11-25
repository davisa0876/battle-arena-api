<?php

namespace Tests\Unit\Application\Battles;

use App\Application\Battles\BattleRewardService;
use App\Domain\Battle\BattleRewardPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class BattleRewardServiceTest extends TestCase
{
    use RefreshDatabase;

    private BattleRewardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(BattleRewardService::class);
    }

    public function test_configured_reward_amounts(): void
    {
        $policy = $this->app->make(BattleRewardPolicy::class);
        $this->assertSame(10, $policy->xpFor('pve', 'win'));
        $this->assertSame(5, $policy->xpFor('pve', 'loss'));
        $this->assertSame(20, $policy->xpFor('pvp', 'win'));
        $this->assertSame(10, $policy->xpFor('pvp', 'loss'));
    }

    public function test_pve_win_awards_ten_xp(): void
    {
        $user = User::factory()->withPlayer()->create();

        $result = $this->service->awardForCompletedMatch($user, [
            'match_id' => 'match-1',
            'mode' => 'pve',
            'outcome' => 'win',
            'verified' => true,
        ]);

        $this->assertSame(10, (int) $result->progress->total_xp);
        $this->assertFalse($result->wasDuplicate);
    }

    public function test_pvp_requires_level_six(): void
    {
        $user = User::factory()->withPlayer()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->awardForCompletedMatch($user, [
            'match_id' => 'match-2',
            'mode' => 'pvp',
            'outcome' => 'win',
            'verified' => true,
        ]);
    }

    public function test_pvp_win_at_level_six_awards_twenty_xp(): void
    {
        $user = User::factory()->withPlayer()->create();
        $user->progress->update(['total_xp' => 1500, 'level' => 6]);

        $result = $this->service->awardForCompletedMatch($user->fresh(['progress']), [
            'match_id' => 'match-3',
            'mode' => 'pvp',
            'outcome' => 'win',
            'verified' => true,
        ]);

        $this->assertSame(1520, (int) $result->progress->total_xp);
    }

    public function test_duplicate_match_reward_is_idempotent(): void
    {
        $user = User::factory()->withPlayer()->create();
        $payload = [
            'match_id' => 'match-dup',
            'mode' => 'pve',
            'outcome' => 'loss',
            'verified' => true,
        ];

        $first = $this->service->awardForCompletedMatch($user, $payload);
        $second = $this->service->awardForCompletedMatch($user, $payload);

        $this->assertFalse($first->wasDuplicate);
        $this->assertTrue($second->wasDuplicate);
        $this->assertSame(5, (int) $user->fresh()->progress->total_xp);
        $this->assertDatabaseCount('player_xp_events', 1);
    }

    public function test_unverified_match_is_rejected(): void
    {
        $user = User::factory()->withPlayer()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->service->awardForCompletedMatch($user, [
            'match_id' => 'x',
            'mode' => 'pve',
            'outcome' => 'win',
            'verified' => false,
        ]);
    }
}
