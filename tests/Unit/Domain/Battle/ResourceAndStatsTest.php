<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle;

use App\Domain\Battle\BattleRules;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\EffectiveStatCalculator;
use App\Domain\Battle\ResourceCalculator;
use App\Domain\Battle\StaminaCost;
use App\Domain\Battle\TypeEffectivenessChart;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResourceAndStatsTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: int}>
     */
    public static function staminaCostProvider(): array
    {
        return [
            'ember (40)' => [40, 15],
            'zero power clamps to min' => [0, 10],
            'power 10' => [10, 10],
            'power 100' => [100, 33],
            'power 250 clamps to max' => [250, 35],
        ];
    }

    #[DataProvider('staminaCostProvider')]
    public function test_stamina_cost_formula(int $power, int $expected): void
    {
        $this->assertSame($expected, StaminaCost::forPower($power));
        $this->assertSame($expected, ResourceCalculator::staminaCostForPower($power, BattleRules::fromArray()));
    }

    public function test_resource_bounds(): void
    {
        $this->assertTrue(ResourceCalculator::canAfford(15, 15));
        $this->assertFalse(ResourceCalculator::canAfford(14, 15));
        $this->assertSame(0, ResourceCalculator::applySpend(10, 25, 100));
        $this->assertSame(85, ResourceCalculator::applySpend(100, 15, 100));
        $this->assertSame(100, ResourceCalculator::applyRecover(95, 15, 100));
        $this->assertSame(0, ResourceCalculator::clamp(-5, 0, 10));
        $this->assertSame(10, ResourceCalculator::clamp(50, 0, 10));
    }

    public function test_effective_stats(): void
    {
        // base 45, level 50: floor(4500/100)=45
        $this->assertSame(105, EffectiveStatCalculator::hp(45, 50));
        $this->assertSame(50, EffectiveStatCalculator::stat(45, 50));
        $this->assertSame(11, EffectiveStatCalculator::hp(1, 1)); // floor(2/100)=0 + 1 + 10
        $this->assertSame(5, EffectiveStatCalculator::stat(1, 1));
    }

    public function test_type_chart_multiplies_dual_types_and_defaults_to_one(): void
    {
        $chart = new TypeEffectivenessChart([
            'fire' => ['grass' => 2, 'water' => 0.5, 'rock' => 0.5],
            'electric' => ['ground' => 0, 'water' => 2],
        ]);

        $this->assertSame(1.0, $chart->multiplier('fire', ['normal']));
        $this->assertSame(2.0, $chart->multiplier('fire', ['grass']));
        $this->assertSame(1.0, $chart->multiplier('fire', ['grass', 'water']));
        $this->assertSame(0.25, $chart->multiplier('fire', ['water', 'rock']));
        $this->assertSame(0.0, $chart->multiplier('electric', ['water', 'ground']));
        $this->assertSame(1.0, $chart->multiplier('ghost', ['normal']));
    }

    public function test_rules_from_array_use_spec_defaults_and_overrides(): void
    {
        $rules = BattleRules::fromArray(['defense' => ['block' => ['stamina_cost' => 12]]]);

        $this->assertSame('pve-0.1', $rules->rulesVersion);
        $this->assertSame(100, $rules->staminaMax);
        $this->assertSame(1.5, $rules->stab);
        $this->assertSame(12, $rules->defenseStaminaCost(DefenseType::Block));
        $this->assertSame(0.5, $rules->defenseDamageMultiplier(DefenseType::Block));
        $this->assertSame(20, $rules->defenseStaminaCost(DefenseType::Dodge));
        $this->assertSame(0.6, $rules->dodgeChance);
        $this->assertSame(15, $rules->recoverStamina);

        $basic = $rules->basicStrikeMove();
        $this->assertTrue($basic->isBasicStrike);
        $this->assertNull($basic->id);
        $this->assertSame(20, $basic->power);
        $this->assertSame('normal', $basic->typeSlug);
        $this->assertSame(0, $basic->staminaCost);

        $this->assertEquals($rules, BattleRules::fromArray($rules->toArray()));
    }
}
