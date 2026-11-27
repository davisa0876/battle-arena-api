<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle;

use App\Domain\Battle\BattleEngine;
use App\Domain\Battle\BattleEvent;
use App\Domain\Battle\BattlePhase;
use App\Domain\Battle\BattleState;
use App\Domain\Battle\BattleTransition;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\OpponentIntent;
use App\Domain\Battle\Random\SeededRandomSource;
use App\Domain\Battle\TypeEffectivenessChart;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Domain\Battle\Support\BattleFixtures;
use Tests\Unit\Domain\Battle\Support\ScriptedRandomSource;

final class BattleEngineTest extends TestCase
{
    private BattleEngine $engine;

    private TypeEffectivenessChart $chart;

    protected function setUp(): void
    {
        $this->engine = new BattleEngine;
        $this->chart = new TypeEffectivenessChart(['fire' => ['normal' => 2.0, 'ghost' => 0.0]]);
    }

    /**
     * @return list<string>
     */
    private function types(BattleTransition $t): array
    {
        return array_map(static fn (BattleEvent $e): string => $e->type->value, $t->events);
    }

    private function firstEvent(BattleTransition $t, string $type): BattleEvent
    {
        foreach ($t->events as $event) {
            if ($event->type->value === $type) {
                return $event;
            }
        }
        $this->fail("Event {$type} not found.");
    }

    /**
     * Player ember (STAB, special, fire) vs normal opponent; chart is neutral for the default tests.
     */
    private function attack(BattleState $state, DefenseType $defense, array $rng = [], ?TypeEffectivenessChart $chart = null): BattleTransition
    {
        return $this->engine->resolvePlayerAttack($state, 0, $defense, new ScriptedRandomSource($rng), $chart ?? new TypeEffectivenessChart);
    }

    private function awaitingDefense(?\App\Domain\Battle\CombatantSnapshot $player = null, ?\App\Domain\Battle\CombatantSnapshot $opponent = null): BattleState
    {
        $opponent ??= BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 100, [], [
            BattleFixtures::move('tackle', 40, 100, 'normal', 'physical', 35, 15),
        ]);
        $base = BattleFixtures::state($player, $opponent);

        return new BattleState(
            $base->id, BattlePhase::AwaitingPlayerDefense, 1, 3, $base->rules, $base->player, $base->opponent,
            OpponentIntent::forMove(0, $opponent->move(0)), 10,
        );
    }

    public function test_player_attack_hit_spends_resources_and_awaits_defense_without_intent(): void
    {
        $t = $this->attack(BattleFixtures::state(), DefenseType::Endure);
        $next = $t->nextState;

        $this->assertSame(BattlePhase::AwaitingPlayerDefense, $next->phase);
        $this->assertNull($next->pendingOpponentAttack);
        $this->assertSame(2, $next->version);
        $this->assertSame(24, $next->player->move(0)->ppCurrent);
        $this->assertSame(85, $next->player->staminaCurrent);
        $this->assertSame(200 - 28, $next->opponent->hpCurrent); // floor(19 * 1.5 STAB)
        $this->assertSame(100, $next->opponent->staminaCurrent);
        $this->assertSame(1, $next->round);
        $this->assertNull($next->outcome);
        $this->assertSame(0, $t->rngCursor);
    }

    public function test_events_are_ordered_with_monotonic_sequence(): void
    {
        $state = BattleFixtures::state();
        $t = $this->attack($state, DefenseType::Endure);

        $this->assertSame([
            'attack_started', 'resources_spent', 'pp_changed', 'stamina_changed',
            'defense_selected', 'damage_applied', 'hp_changed',
        ], $this->types($t));
        $this->assertSame(range(1, 7), array_map(static fn (BattleEvent $e): int => $e->sequence, $t->events));
        $this->assertSame(7, $t->nextState->lastEventSequence);

        $damage = $this->firstEvent($t, 'damage_applied');
        $this->assertSame(CombatSide::Player, $damage->actor);
        $this->assertSame(CombatSide::Opponent, $damage->target);
        $this->assertSame(28, $damage->payload['damage']);
        $this->assertSame(200, $damage->payload['hp_before']);
        $this->assertSame(172, $damage->payload['hp_after']);
    }

    public function test_event_sequence_continues_from_state(): void
    {
        $t = $this->engine->resolveCommittedAttack($this->awaitingDefense(), DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);

        $this->assertSame(11, $t->events[0]->sequence);
    }

    public function test_type_effectiveness_and_immunity_through_engine(): void
    {
        $hit = $this->attack(BattleFixtures::state(), DefenseType::Endure, [], $this->chart);
        $this->assertSame(200 - 57, $hit->nextState->opponent->hpCurrent); // 19 * 1.5 * 2 = 57

        $ghost = BattleFixtures::combatant(CombatSide::Opponent, ['ghost']);
        $immune = $this->attack(BattleFixtures::state(null, $ghost), DefenseType::Endure, [], $this->chart);

        $this->assertSame(200, $immune->nextState->opponent->hpCurrent);
        $this->assertSame(24, $immune->nextState->player->move(0)->ppCurrent, 'resources still spent');
        $this->assertSame(85, $immune->nextState->player->staminaCurrent);
        $this->assertTrue($this->firstEvent($immune, 'damage_applied')->payload['is_immune']);
        $this->assertNotContains('hp_changed', $this->types($immune));
    }

    public function test_miss_spends_resources_and_deals_no_damage(): void
    {
        $state = BattleFixtures::state(BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 100, [], [
            BattleFixtures::move('ember', 40, 50),
        ]));

        $t = $this->attack($state, DefenseType::Endure, [80]); // roll 80 > accuracy 50

        $this->assertContains('attack_missed', $this->types($t));
        $this->assertNotContains('damage_applied', $this->types($t));
        $this->assertSame(200, $t->nextState->opponent->hpCurrent);
        $this->assertSame(24, $t->nextState->player->move(0)->ppCurrent);
        $this->assertSame(85, $t->nextState->player->staminaCurrent);
        $this->assertSame(BattlePhase::AwaitingPlayerDefense, $t->nextState->phase);
        $this->assertSame(1, $t->rngCursor);
    }

    public function test_accuracy_roll_within_range_hits(): void
    {
        $state = BattleFixtures::state(BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 100, [], [
            BattleFixtures::move('ember', 40, 50),
        ]));

        $t = $this->attack($state, DefenseType::Endure, [50]);

        $this->assertSame(172, $t->nextState->opponent->hpCurrent);
    }

    public function test_successful_dodge_avoids_all_damage_and_still_costs_stamina(): void
    {
        $t = $this->attack(BattleFixtures::state(), DefenseType::Dodge, [0.3]);

        $this->assertContains('defense_dodged', $this->types($t));
        $this->assertNotContains('damage_applied', $this->types($t));
        $this->assertSame(200, $t->nextState->opponent->hpCurrent);
        $this->assertSame(80, $t->nextState->opponent->staminaCurrent);
        $this->assertSame(85, $t->nextState->player->staminaCurrent);
    }

    public function test_failed_dodge_takes_normal_damage(): void
    {
        $t = $this->attack(BattleFixtures::state(), DefenseType::Dodge, [0.9]);

        $this->assertNotContains('defense_dodged', $this->types($t));
        $this->assertSame(172, $t->nextState->opponent->hpCurrent);
        $this->assertSame(80, $t->nextState->opponent->staminaCurrent);
    }

    public function test_dodge_is_not_rolled_when_attack_misses(): void
    {
        $state = BattleFixtures::state(BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 100, [], [
            BattleFixtures::move('ember', 40, 50),
        ]));

        $t = $this->attack($state, DefenseType::Dodge, [99]);

        $this->assertContains('attack_missed', $this->types($t));
        $this->assertNotContains('defense_dodged', $this->types($t));
        $this->assertSame(1, $t->rngCursor);
        $this->assertSame(80, $t->nextState->opponent->staminaCurrent, 'dodge cost still paid once');
    }

    public function test_block_halves_damage_42_to_21(): void
    {
        $player = BattleFixtures::combatant(CombatSide::Player, ['normal'], 50, 200, 100, ['attack' => 120], [
            BattleFixtures::move('tackle', 40, 100, 'fire', 'physical'),
        ]);
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 100, ['defense' => 108]);

        $t = $this->attack(BattleFixtures::state($player, $opponent), DefenseType::Block, [], $this->chart);

        $damage = $this->firstEvent($t, 'damage_applied');
        $this->assertSame(21, $damage->payload['base_damage']);
        $this->assertSame(42, $damage->payload['modified_damage']);
        $this->assertSame(21, $damage->payload['damage']);
        $this->assertSame(179, $t->nextState->opponent->hpCurrent);
        $this->assertSame(90, $t->nextState->opponent->staminaCurrent);
        $this->assertContains('damage_blocked', $this->types($t));
    }

    public function test_endure_takes_normal_damage_without_stamina_change(): void
    {
        $t = $this->attack(BattleFixtures::state(), DefenseType::Endure);

        $this->assertSame(100, $t->nextState->opponent->staminaCurrent);
        $this->assertSame(172, $t->nextState->opponent->hpCurrent);
    }

    public function test_recover_is_capped_at_max_stamina_and_still_takes_damage(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 95);

        $t = $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Recover);

        $this->assertSame(100, $t->nextState->opponent->staminaCurrent);
        $this->assertSame(172, $t->nextState->opponent->hpCurrent);
        $recovered = $this->firstEvent($t, 'stamina_recovered');
        $this->assertSame(5, $recovered->payload['amount']);
        $this->assertSame(100, $recovered->payload['after']);
    }

    public function test_recover_adds_full_amount_when_below_cap(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 40);

        $t = $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Recover);

        $this->assertSame(55, $t->nextState->opponent->staminaCurrent);
    }

    public function test_heal_spends_stamina_and_restores_hp_after_damage(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 100, 80);

        $t = $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Heal);

        // Default heal: -25 stamina, take damage, then +20 HP (if alive).
        $this->assertSame(55, $t->nextState->opponent->staminaCurrent);
        $this->assertSame(92, $t->nextState->opponent->hpCurrent); // 100 - 28 damage + 20 heal (fixture damage)
        $heal = collect($t->events)->first(
            static fn ($e): bool => $e->type->value === 'hp_changed' && ($e->payload['reason'] ?? null) === 'heal',
        );
        $this->assertNotNull($heal);
        $this->assertSame(20, $heal->payload['amount']);
    }

    public function test_knockout_finishes_battle_with_win_and_no_pending_attack(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 5);

        $t = $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Endure);

        $next = $t->nextState;
        $this->assertSame(BattlePhase::Finished, $next->phase);
        $this->assertSame('win', $next->outcome);
        $this->assertSame(0, $next->opponent->hpCurrent);
        $this->assertNull($next->pendingOpponentAttack);
        $this->assertSame(['combatant_fainted', 'battle_finished'], array_slice($this->types($t), -2));
        $this->assertSame('player', $this->firstEvent($t, 'battle_finished')->payload['winner']);
    }

    public function test_committed_attack_returns_to_player_attack_and_starts_new_round(): void
    {
        $t = $this->engine->resolveCommittedAttack($this->awaitingDefense(), DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);

        $next = $t->nextState;
        $this->assertSame(BattlePhase::AwaitingPlayerAttack, $next->phase);
        $this->assertSame(2, $next->round);
        $this->assertSame(4, $next->version);
        $this->assertNull($next->pendingOpponentAttack);
        $this->assertSame(172, $next->player->hpCurrent); // normal STAB: floor(19 * 1.5)
        $this->assertSame(34, $next->opponent->move(0)->ppCurrent);
        $this->assertSame(85, $next->opponent->staminaCurrent);
        $this->assertSame(CombatSide::Opponent, $this->firstEvent($t, 'attack_started')->actor);
    }

    public function test_player_defense_block_dodge_and_recover_against_committed_attack(): void
    {
        $none = new TypeEffectivenessChart;

        $block = $this->engine->resolveCommittedAttack($this->awaitingDefense(), DefenseType::Block, new ScriptedRandomSource, $none);
        $this->assertSame(200 - 14, $block->nextState->player->hpCurrent); // floor(28 * 0.5)
        $this->assertSame(90, $block->nextState->player->staminaCurrent);

        $dodge = $this->engine->resolveCommittedAttack($this->awaitingDefense(), DefenseType::Dodge, new ScriptedRandomSource([0.1]), $none);
        $this->assertSame(200, $dodge->nextState->player->hpCurrent);
        $this->assertSame(80, $dodge->nextState->player->staminaCurrent);

        $low = BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 97);
        $recover = $this->engine->resolveCommittedAttack($this->awaitingDefense($low), DefenseType::Recover, new ScriptedRandomSource, $none);
        $this->assertSame(100, $recover->nextState->player->staminaCurrent);
        $this->assertSame(172, $recover->nextState->player->hpCurrent);
    }

    public function test_player_knockout_finishes_with_loss(): void
    {
        $player = BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 10);

        $t = $this->engine->resolveCommittedAttack($this->awaitingDefense($player), DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);

        $this->assertSame(BattlePhase::Finished, $t->nextState->phase);
        $this->assertSame('loss', $t->nextState->outcome);
        $this->assertSame(0, $t->nextState->player->hpCurrent);
        $this->assertSame(1, $t->nextState->round, 'round does not advance on knockout');
        $this->assertContains('battle_finished', $this->types($t));
    }

    public function test_pp_cannot_go_below_zero(): void
    {
        $player = BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 100, [], [BattleFixtures::move('ember', 40, 100, 'fire', 'special', 0)]);

        $this->expectException(DomainException::class);
        $this->attack(BattleFixtures::state($player), DefenseType::Endure);
    }

    public function test_stamina_cannot_go_below_zero(): void
    {
        $player = BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 14);

        $this->expectException(DomainException::class);
        $this->attack(BattleFixtures::state($player), DefenseType::Endure);
    }

    public function test_defense_requires_enough_stamina(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 19);

        $this->expectException(DomainException::class);
        $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Dodge);
    }

    public function test_endure_and_recover_are_always_affordable(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 0);

        $this->assertSame(172, $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Endure)->nextState->opponent->hpCurrent);
        $this->assertSame(15, $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Recover)->nextState->opponent->staminaCurrent);
    }

    public function test_basic_strike_only_when_no_move_is_usable_and_costs_nothing(): void
    {
        $exhausted = BattleFixtures::combatant(CombatSide::Player, ['normal'], 50, 200, 5);
        $state = BattleFixtures::state($exhausted);

        $t = $this->engine->resolvePlayerAttack($state, null, DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);

        $this->assertSame(5, $t->nextState->player->staminaCurrent);
        $this->assertSame(25, $t->nextState->player->move(0)->ppCurrent);
        // power 20 => base floor(22*20/50 + 2) = 10 ; normal STAB x1.5 => 15
        $this->assertSame(200 - 15, $t->nextState->opponent->hpCurrent);
        $this->assertTrue($this->firstEvent($t, 'attack_started')->payload['is_basic_strike']);
        $this->assertNotContains('pp_changed', $this->types($t));
    }

    public function test_basic_strike_rejected_while_a_move_is_usable(): void
    {
        $this->expectException(DomainException::class);
        $this->engine->resolvePlayerAttack(BattleFixtures::state(), null, DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);
    }

    public function test_recover_skip_restores_stamina_and_skips_damage_when_moves_are_stamina_gated(): void
    {
        $lowStamina = BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 5);
        $state = BattleFixtures::state($lowStamina);
        $this->assertTrue($state->player->canRecoverSkipAttack());

        $t = $this->engine->resolvePlayerRecoverSkipAttack($state, 0);

        $this->assertSame(20, $t->nextState->player->staminaCurrent);
        $this->assertSame(200, $t->nextState->opponent->hpCurrent);
        $this->assertSame(BattlePhase::AwaitingPlayerDefense, $t->nextState->phase);
        $this->assertContains('attack_skipped', $this->types($t));
        $this->assertContains('stamina_recovered', $this->types($t));
        $this->assertSame(15, $this->firstEvent($t, 'stamina_recovered')->payload['amount']);
    }

    public function test_recover_skip_rejected_while_a_move_is_usable(): void
    {
        $this->expectException(DomainException::class);
        $this->engine->resolvePlayerRecoverSkipAttack(BattleFixtures::state(), 0);
    }

    public function test_invalid_phase_throws_domain_exception(): void
    {
        $engine = $this->engine;
        $rng = new ScriptedRandomSource;
        $chart = new TypeEffectivenessChart;

        // player attack while awaiting defense
        try {
            $engine->resolvePlayerAttack($this->awaitingDefense(), 0, DefenseType::Endure, $rng, $chart);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        // defense while awaiting attack
        try {
            $engine->resolveCommittedAttack(BattleFixtures::state(), DefenseType::Endure, $rng, $chart);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        // finished battle accepts nothing
        $finished = $this->attack(BattleFixtures::state(null, BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 1)), DefenseType::Endure)->nextState;
        $this->expectException(DomainException::class);
        $engine->resolvePlayerAttack($finished, 0, DefenseType::Endure, $rng, $chart);
    }

    public function test_committed_attack_requires_an_intent(): void
    {
        $t = $this->attack(BattleFixtures::state(), DefenseType::Endure);

        $this->expectException(DomainException::class);
        $this->engine->resolveCommittedAttack($t->nextState, DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);
    }

    public function test_committed_attack_cannot_be_changed_or_replaced(): void
    {
        $state = $this->awaitingDefense();

        try {
            $this->engine->resolveAttackExchange($state, CombatSide::Opponent, null, DefenseType::Endure, new ScriptedRandomSource, new TypeEffectivenessChart);
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DomainException::class);
        $state->withPendingOpponentAttack(OpponentIntent::forMove(0, $state->opponent->move(0)));
    }

    public function test_attaching_intent_after_player_attack_then_resolving_full_round(): void
    {
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, ['normal'], 50, 200, 100, [], [
            BattleFixtures::move('tackle', 40, 100, 'normal', 'physical', 35, 15),
        ]);
        $none = new TypeEffectivenessChart;

        $afterAttack = $this->attack(BattleFixtures::state(null, $opponent), DefenseType::Endure)->nextState;
        $committed = $afterAttack->withPendingOpponentAttack(OpponentIntent::forMove(0, $opponent->move(0)));

        $this->assertSame($afterAttack->version, $committed->version);
        $this->assertSame(['type_slug' => 'normal', 'damage_class' => 'physical', 'is_basic_strike' => false], $committed->pendingOpponentAttack->telegraph());

        $afterDefense = $this->engine->resolveCommittedAttack($committed, DefenseType::Endure, new ScriptedRandomSource, $none)->nextState;

        $this->assertSame(BattlePhase::AwaitingPlayerAttack, $afterDefense->phase);
        $this->assertSame(2, $afterDefense->round);
        $this->assertSame(3, $afterDefense->version);
    }

    public function test_cannot_attach_intent_in_wrong_phase_or_for_unusable_move(): void
    {
        $state = BattleFixtures::state();
        try {
            $state->withPendingOpponentAttack(OpponentIntent::forMove(0, $state->opponent->move(0)));
            $this->fail('Expected DomainException');
        } catch (DomainException) {
            $this->addToAssertionCount(1);
        }

        $afterAttack = $this->attack($state, DefenseType::Endure)->nextState;
        $this->expectException(DomainException::class);
        $afterAttack->withPendingOpponentAttack(OpponentIntent::basicStrike($state->rules->basicStrikeMove()));
    }

    public function test_same_seed_and_actions_produce_identical_results(): void
    {
        $rules = BattleFixtures::rules(['random_damage_min' => 0.9, 'random_damage_max' => 1.0]);
        $player = BattleFixtures::combatant(CombatSide::Player, ['fire'], 50, 200, 100, [], [BattleFixtures::move('ember', 40, 70)]);

        $run = function (int $seed) use ($player, $rules): array {
            $rng = SeededRandomSource::fromSeed($seed);
            $state = BattleFixtures::state($player, null, $rules);
            $log = [];
            for ($i = 0; $i < 3; $i++) {
                $t = $this->engine->resolvePlayerAttack($state, 0, DefenseType::Dodge, $rng, new TypeEffectivenessChart);
                foreach ($t->events as $e) {
                    $log[] = $e->toArray();
                }
                $state = $t->nextState->with(
                    BattlePhase::AwaitingPlayerAttack, 1, $t->nextState->version, $t->nextState->player,
                    $t->nextState->opponent->withStamina(100), $t->nextState->lastEventSequence, null,
                );
            }

            return ['log' => $log, 'state' => $state->toArray(), 'cursor' => $rng->cursor()];
        };

        $this->assertSame($run(1234), $run(1234));
        $this->assertGreaterThan(0, $run(1234)['cursor']);
    }

    public function test_resuming_rng_from_persisted_cursor_matches_continuous_run(): void
    {
        $state = BattleFixtures::state(null, null, BattleFixtures::rules(['random_damage_min' => 0.9, 'random_damage_max' => 1.0]));
        $none = new TypeEffectivenessChart;

        $continuous = SeededRandomSource::fromSeed(77);
        $first = $this->engine->resolvePlayerAttack($state, 0, DefenseType::Dodge, $continuous, $none);

        $resumed = SeededRandomSource::fromSeed(77, $first->rngCursor);
        $this->assertSame($continuous->state(), $resumed->state());
        $this->assertSame($continuous->nextFloat(), $resumed->nextFloat());
    }

    public function test_state_round_trips_through_array(): void
    {
        $state = $this->awaitingDefense();

        $this->assertEquals($state, BattleState::fromArray($state->toArray()));
        $this->assertEquals($state, BattleState::fromArray(json_decode(json_encode($state->toArray()), true)));
    }
}
