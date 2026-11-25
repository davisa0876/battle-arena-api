<?php

namespace Tests\Unit\Domain\Selection;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Selection\CreatureSelectionPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CreatureSelectionPolicyTest extends TestCase
{
    private CreatureSelectionPolicy $selection;

    private BattleEligibilityPolicy $battle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->selection = $this->app->make(CreatureSelectionPolicy::class);
        $this->battle = $this->app->make(BattleEligibilityPolicy::class);
    }

    #[DataProvider('stage1Provider')]
    public function test_stage1_allowances(int $level, int $expected): void
    {
        $this->assertSame($expected, $this->selection->stage1Allowance($level));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function stage1Provider(): array
    {
        return [
            '1' => [1, 1],
            '2' => [2, 2],
            '5' => [5, 5],
            '10' => [10, 10],
            '20' => [20, 10],
        ];
    }

    #[DataProvider('stage2Provider')]
    public function test_stage2_allowances(int $level, int $expected): void
    {
        $this->assertSame($expected, $this->selection->stage2Allowance($level));
    }

    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function stage2Provider(): array
    {
        return [
            '1' => [1, 0],
            '5' => [5, 0],
            '9' => [9, 0],
            '10' => [10, 1],
            '11' => [11, 2],
            '15' => [15, 6],
            '20' => [20, 11],
        ];
    }

    public function test_stage3_is_locked(): void
    {
        $this->assertFalse($this->selection->stage3Unlocked());
        $this->assertFalse($this->selection->canSelectStage(99, 3));
    }

    #[DataProvider('pvpProvider')]
    public function test_pvp_eligibility(int $level, bool $expected): void
    {
        $this->assertSame($expected, $this->battle->canEnterPvp($level));
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function pvpProvider(): array
    {
        return [
            '1' => [1, false],
            '5' => [5, false],
            '6' => [6, true],
            '10' => [10, true],
        ];
    }
}
