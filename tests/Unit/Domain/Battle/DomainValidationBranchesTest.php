<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle;

use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Battle\BattleRules;
use App\Domain\Battle\BattleState;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\OpponentIntent;
use App\Domain\Battle\Pvp\PvpAttackCommitment;
use App\Domain\Battle\Pvp\PvpBattlePhase;
use App\Domain\Battle\Pvp\PvpBattleState;
use App\Domain\Battle\TypeEffectivenessChart;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Tests\Unit\Domain\Battle\Support\BattleFixtures;

class DomainValidationBranchesTest extends TestCase
{
    private function assertInvalid(callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertInstanceOf(InvalidArgumentException::class, $e);
        }
    }

    #[Test]
    public function test_move_snapshot_rejects_invalid_values(): void
    {
        $this->assertInvalid(fn () => new MoveSnapshot(1, 'x', 'X', -1, 100, 1, 1, 0, 'physical', 'normal'));
    }

    #[Test]
    public function test_move_snapshot_rejects_bad_accuracy_pp_and_class(): void
    {
        try {
            new MoveSnapshot(1, 'x', 'X', 10, 101, 1, 1, 0, 'physical', 'normal');
            $this->fail('accuracy');
        } catch (InvalidArgumentException) {
        }
        try {
            new MoveSnapshot(1, 'x', 'X', 10, 100, 2, 1, 0, 'physical', 'normal');
            $this->fail('pp');
        } catch (InvalidArgumentException) {
        }
        try {
            new MoveSnapshot(1, 'x', 'X', 10, 100, 1, 1, 0, 'status', 'normal');
            $this->fail('class');
        } catch (InvalidArgumentException) {
        }

        $basic = new MoveSnapshot(null, 'basic_strike', 'Basic', 10, 100, 0, 0, 0, 'physical', 'normal', isBasicStrike: true);
        $this->assertTrue($basic->hasPp());
    }

    #[Test]
    public function test_combatant_snapshot_rejects_invalid_values(): void
    {
        $valid = fn (array $over = []) => new CombatantSnapshot(
            side: CombatSide::Player,
            name: 'A',
            slug: 'a',
            level: $over['level'] ?? 5,
            types: ['normal'],
            hpCurrent: $over['hp'] ?? 10,
            hpMax: $over['hpMax'] ?? 10,
            staminaCurrent: $over['sta'] ?? 5,
            staminaMax: $over['staMax'] ?? 5,
            stats: $over['stats'] ?? [
                'attack' => 1, 'defense' => 1, 'special_attack' => 1, 'special_defense' => 1, 'speed' => 1,
            ],
            moves: $over['moves'] ?? [],
        );

        foreach ([
            ['level' => 0],
            ['hp' => 11],
            ['sta' => 6],
            ['stats' => ['attack' => 1, 'defense' => 1, 'special_attack' => 1, 'special_defense' => 1]],
            ['moves' => [1 => BattleFixtures::move()]],
        ] as $bad) {
            try {
                $valid($bad);
                $this->fail(json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }

        $emptyMoves = $valid(['moves' => []]);
        $this->assertTrue($emptyMoves->canBasicStrike());
        $this->assertFalse($emptyMoves->canRecoverSkipAttack());
    }

    #[Test]
    public function test_opponent_intent_validation_branches(): void
    {
        $move = BattleFixtures::move();
        $basic = new MoveSnapshot(null, 'basic_strike', 'Basic', 10, 100, 0, 0, 0, 'physical', 'normal', isBasicStrike: true);

        $this->assertInvalid(fn () => new OpponentIntent(0, true, $basic));
        $this->assertInvalid(fn () => new OpponentIntent(null, false, $move));
        $this->assertInvalid(fn () => new OpponentIntent(0, false, $basic));
    }

    #[Test]
    public function test_pvp_attack_commitment_validation_branches(): void
    {
        $move = BattleFixtures::move();
        $basic = new MoveSnapshot(null, 'basic_strike', 'Basic', 10, 100, 0, 0, 0, 'physical', 'normal', isBasicStrike: true);

        $this->assertInvalid(fn () => new PvpAttackCommitment(0, 0, $move));
        $this->assertInvalid(fn () => new PvpAttackCommitment(1, null, $move));
        $this->assertInvalid(fn () => new PvpAttackCommitment(1, 0, $basic));
    }

    #[Test]
    public function test_battle_state_validation_branches(): void
    {
        $rules = BattleFixtures::rules();
        $player = BattleFixtures::combatant(CombatSide::Player);
        $opponent = BattleFixtures::combatant(CombatSide::Opponent);

        try {
            new BattleState('id', BattlePhase::AwaitingPlayerAttack, 1, 1, $rules, $opponent, $player);
            $this->fail('sides');
        } catch (InvalidArgumentException) {
        }
        try {
            new BattleState('id', BattlePhase::AwaitingPlayerAttack, 0, 1, $rules, $player, $opponent);
            $this->fail('round');
        } catch (InvalidArgumentException) {
        }
        try {
            new BattleState('id', BattlePhase::AwaitingPlayerAttack, 1, 1, $rules, $player, $opponent, outcome: 'draw');
            $this->fail('outcome');
        } catch (InvalidArgumentException) {
        }
        try {
            new BattleState('id', BattlePhase::Finished, 1, 1, $rules, $player, $opponent, outcome: null);
            $this->fail('finished without outcome');
        } catch (InvalidArgumentException) {
        }
        try {
            new BattleState(
                'id',
                BattlePhase::AwaitingPlayerAttack,
                1,
                1,
                $rules,
                $player,
                $opponent,
                pendingOpponentAttack: OpponentIntent::forMove(0, BattleFixtures::move()),
            );
            $this->fail('pending phase');
        } catch (InvalidArgumentException) {
        }

        $state = BattleState::start('id', $rules, $player, $opponent);
        $updated = $state->withCombatant($player->withHp(1));
        $this->assertSame(1, $updated->player->hpCurrent);
        $updatedOpp = $state->withCombatant($opponent->withHp(1));
        $this->assertSame(1, $updatedOpp->opponent->hpCurrent);
    }

    #[Test]
    public function test_pvp_battle_state_validation_branches(): void
    {
        $rules = BattleFixtures::rules();
        $host = BattleFixtures::combatant(CombatSide::Player);
        $guest = BattleFixtures::combatant(CombatSide::Opponent);

        $make = function (array $over = []) use ($rules, $host, $guest): PvpBattleState {
            return new PvpBattleState(
                id: 'b',
                roomId: 'r',
                phase: $over['phase'] ?? PvpBattlePhase::AwaitingAttack,
                turnNumber: $over['turn'] ?? 1,
                version: $over['version'] ?? 1,
                rules: $rules,
                hostUserId: $over['host'] ?? 1,
                guestUserId: $over['guest'] ?? 2,
                host: $host,
                guest: $guest,
                currentAttackerUserId: $over['atk'] ?? 1,
                currentDefenderUserId: $over['def'] ?? 2,
                pendingAttack: $over['pending'] ?? null,
                lastEventSequence: $over['seq'] ?? 0,
                winnerUserId: $over['win'] ?? null,
                loserUserId: $over['lose'] ?? null,
            );
        };

        foreach ([
            ['host' => 1, 'guest' => 1],
            ['turn' => 0],
            ['atk' => 9, 'def' => 2],
            ['atk' => 1, 'def' => 1],
            ['phase' => PvpBattlePhase::Finished],
            ['phase' => PvpBattlePhase::AwaitingDefense],
            [
                'phase' => PvpBattlePhase::AwaitingAttack,
                'pending' => new PvpAttackCommitment(1, 0, BattleFixtures::move()),
            ],
        ] as $bad) {
            try {
                $make($bad);
                $this->fail(json_encode($bad));
            } catch (InvalidArgumentException) {
            }
        }

        $ok = $make();
        $this->assertSame(1, $ok->userIdForSide(CombatSide::Player));
        $this->assertSame(2, $ok->userIdForSide(CombatSide::Opponent));
        $copied = $ok->withCombatants($host->withHp(1), $guest);
        $this->assertSame(1, $copied->host->hpCurrent);
    }

    #[Test]
    public function test_battle_rules_validation_branches(): void
    {
        $this->assertInvalid(fn () => BattleRules::fromArray(['stab' => 0.5]));
        $this->assertInvalid(fn () => BattleRules::fromArray(['stamina_max' => 0]));
        $this->assertInvalid(fn () => BattleRules::fromArray(['random_damage_min' => 0]));
        $this->assertInvalid(fn () => BattleRules::fromArray([
            'defense' => ['dodge' => ['dodge_chance' => 2.0]],
        ]));
        $this->assertInvalid(fn () => BattleRules::fromArray([
            'defense' => ['recover' => ['stamina_recover' => -1]],
        ]));
        $this->assertInvalid(fn () => BattleRules::fromArray([
            'defense' => ['heal' => ['hp_recover' => -1]],
        ]));

        $ok = BattleRules::fromArray();
        $this->assertInvalid(fn () => new BattleRules(
            rulesVersion: $ok->rulesVersion,
            stab: $ok->stab,
            staminaMax: $ok->staminaMax,
            randomDamageMin: $ok->randomDamageMin,
            randomDamageMax: $ok->randomDamageMax,
            staminaCostFormula: $ok->staminaCostFormula,
            defenseStaminaCosts: [],
            defenseDamageMultipliers: $ok->defenseDamageMultipliers,
            dodgeChance: $ok->dodgeChance,
            recoverStamina: $ok->recoverStamina,
            recoverHp: $ok->recoverHp,
            basicStrike: $ok->basicStrike,
        ));
        $this->assertInvalid(fn () => new BattleRules(
            rulesVersion: $ok->rulesVersion,
            stab: $ok->stab,
            staminaMax: $ok->staminaMax,
            randomDamageMin: $ok->randomDamageMin,
            randomDamageMax: $ok->randomDamageMax,
            staminaCostFormula: $ok->staminaCostFormula,
            defenseStaminaCosts: $ok->defenseStaminaCosts,
            defenseDamageMultipliers: [],
            dodgeChance: $ok->dodgeChance,
            recoverStamina: $ok->recoverStamina,
            recoverHp: $ok->recoverHp,
            basicStrike: $ok->basicStrike,
        ));
    }

    #[Test]
    public function test_type_chart_to_array_and_reward_policy_invalid_mode(): void
    {
        $chart = new TypeEffectivenessChart(['fire' => ['grass' => 2]]);
        $this->assertSame(['fire' => ['grass' => 2]], $chart->toArray());

        $policy = $this->app->make(BattleRewardPolicy::class);
        try {
            $policy->rewardsForMode('arena');
            $this->fail('mode');
        } catch (InvalidArgumentException) {
        }
        try {
            $policy->xpFor('pve', 'draw');
            $this->fail('outcome');
        } catch (InvalidArgumentException) {
        }
    }
}
