<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle;

use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\DamageCalculator;
use App\Domain\Battle\DamageResult;
use App\Domain\Battle\MoveSnapshot;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Battle\Support\BattleFixtures;
use Tests\Unit\Domain\Battle\Support\ScriptedRandomSource;

final class DamageCalculatorTest extends TestCase
{
    private function calc(
        CombatantSnapshot $attacker,
        CombatantSnapshot $defender,
        MoveSnapshot $move,
        float $type = 1.0,
        float $random = 1.0,
        float $defense = 1.0,
    ): DamageResult {
        return DamageCalculator::calculate($attacker, $defender, $move, $type, $random, $defense, BattleFixtures::rules());
    }

    private function normalAttacker(array $stats = []): CombatantSnapshot
    {
        return BattleFixtures::combatant(CombatSide::Player, ['normal'], 50, 200, 100, $stats);
    }

    public function test_base_damage_formula(): void
    {
        // (2*50/5+2)=22 ; 22*40*100/100/50 + 2 = 19.6 -> 19
        $result = $this->calc($this->normalAttacker(), BattleFixtures::combatant(CombatSide::Opponent), BattleFixtures::move());

        $this->assertSame(19, $result->baseDamage);
        $this->assertSame(19, $result->damage);
    }

    public function test_physical_move_uses_attack_and_defense(): void
    {
        $move = BattleFixtures::move('tackle', 40, 100, 'fire', 'physical');
        $attacker = $this->normalAttacker(['attack' => 150, 'special_attack' => 10]);
        $defender = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 100, ['defense' => 100, 'special_defense' => 1]);

        $this->assertSame(28, $this->calc($attacker, $defender, $move)->damage);
    }

    public function test_special_move_uses_special_attack_and_special_defense(): void
    {
        $move = BattleFixtures::move('ember', 40, 100, 'fire', 'special');
        $attacker = $this->normalAttacker(['attack' => 999, 'special_attack' => 60]);
        $defender = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 100, ['defense' => 1, 'special_defense' => 100]);

        // 22*40*60/100/50 + 2 = 12.56 -> 12
        $this->assertSame(12, $this->calc($attacker, $defender, $move)->damage);
    }

    public function test_stab_applies_to_same_type_only(): void
    {
        $fireAttacker = BattleFixtures::combatant(CombatSide::Player, ['fire']);
        $defender = BattleFixtures::combatant(CombatSide::Opponent);
        $move = BattleFixtures::move();

        $stab = $this->calc($fireAttacker, $defender, $move);
        $noStab = $this->calc($this->normalAttacker(), $defender, $move);

        $this->assertSame(1.5, $stab->stab);
        $this->assertSame(28, $stab->damage); // floor(19 * 1.5)
        $this->assertSame(1.0, $noStab->stab);
        $this->assertSame(19, $noStab->damage);
    }

    public function test_type_multiplier_is_applied(): void
    {
        $defender = BattleFixtures::combatant(CombatSide::Opponent);

        $this->assertSame(38, $this->calc($this->normalAttacker(), $defender, BattleFixtures::move(), 2.0)->damage);
        $this->assertSame(9, $this->calc($this->normalAttacker(), $defender, BattleFixtures::move(), 0.5)->damage);
        $this->assertSame(76, $this->calc($this->normalAttacker(), $defender, BattleFixtures::move(), 4.0)->damage);
    }

    public function test_immunity_always_deals_zero_even_with_minimum_rule(): void
    {
        $result = $this->calc($this->normalAttacker(), BattleFixtures::combatant(CombatSide::Opponent), BattleFixtures::move(), 0.0);

        $this->assertTrue($result->isImmune);
        $this->assertSame(0, $result->damage);
        $this->assertFalse($result->minimumApplied);
    }

    public function test_block_example_42_becomes_21(): void
    {
        // L=50, P=40, A=120, D=108 -> base 21 ; x2 type -> 42 ; block x0.5 -> 21
        $move = BattleFixtures::move('tackle', 40, 100, 'fire', 'physical');
        $attacker = $this->normalAttacker(['attack' => 120]);
        $defender = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 100, ['defense' => 108]);

        $result = $this->calc($attacker, $defender, $move, 2.0, 1.0, 0.5);

        $this->assertSame(21, $result->baseDamage);
        $this->assertSame(42, $result->modifiedDamage);
        $this->assertSame(21, $result->damage);
    }

    public function test_random_factor_is_applied_before_defense(): void
    {
        $defender = BattleFixtures::combatant(CombatSide::Opponent);

        // 19 * 0.9 = 17.1 -> 17
        $this->assertSame(17, $this->calc($this->normalAttacker(), $defender, BattleFixtures::move(), 1.0, 0.9)->damage);
    }

    public function test_damage_is_clamped_to_current_hp(): void
    {
        $defender = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 7);

        $result = $this->calc($this->normalAttacker(), $defender, BattleFixtures::move());

        $this->assertSame(7, $result->damage);
        $this->assertSame(19, $result->unclampedDamage);
    }

    public function test_minimum_one_damage_for_connected_non_immune_hit(): void
    {
        // base is 2 for tiny power/attack; x0.25 type -> floor(0.5) = 0 -> minimum 1
        $move = BattleFixtures::move('poke', 1, 100, 'fire', 'physical');
        $attacker = BattleFixtures::combatant(CombatSide::Player, ['normal'], 1, 200, 100, ['attack' => 1]);
        $defender = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 1, 200, 100, ['defense' => 999]);

        $result = $this->calc($attacker, $defender, $move, 0.25);

        $this->assertSame(2, $result->baseDamage);
        $this->assertSame(0, $result->modifiedDamage);
        $this->assertSame(1, $result->damage);
        $this->assertTrue($result->minimumApplied);
    }

    public function test_random_factor_roll_is_deterministic_and_skips_draw_when_degenerate(): void
    {
        $fixed = new ScriptedRandomSource([]);
        $this->assertSame(1.0, DamageCalculator::rollRandomFactor(BattleFixtures::rules(), $fixed));
        $this->assertSame(0, $fixed->cursor());

        $ranged = BattleFixtures::rules(['random_damage_min' => 0.9, 'random_damage_max' => 1.0]);
        $this->assertSame(0.95, DamageCalculator::rollRandomFactor($ranged, new ScriptedRandomSource([0.5])));
    }
}
