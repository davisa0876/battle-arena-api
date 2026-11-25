<?php

namespace Tests\Unit\Application\Battles;

use App\Application\Battles\CombatLogBuilder;
use PHPUnit\Framework\TestCase;

class CombatLogBuilderTest extends TestCase
{
    public function test_builds_player_and_opponent_exchanges_with_swapped_roles(): void
    {
        $builder = new CombatLogBuilder;
        $names = ['player' => 'Bulbasaur', 'opponent' => 'Caterpie'];

        $log = $builder->build([
            ['sequence' => 1, 'type' => 'battle_started', 'actor' => null, 'target' => null, 'payload' => []],
            [
                'sequence' => 2,
                'type' => 'attack_started',
                'actor' => 'player',
                'target' => 'opponent',
                'payload' => ['move_name' => 'Vine Whip', 'move_slug' => 'vine-whip', 'round' => 1],
            ],
            [
                'sequence' => 3,
                'type' => 'resources_spent',
                'actor' => 'player',
                'target' => 'player',
                'payload' => ['stamina_spent' => 10, 'pp_spent' => 1],
            ],
            [
                'sequence' => 4,
                'type' => 'defense_selected',
                'actor' => 'opponent',
                'target' => 'player',
                'payload' => ['defense' => 'block', 'stamina_cost' => 10],
            ],
            [
                'sequence' => 5,
                'type' => 'damage_blocked',
                'actor' => 'opponent',
                'target' => 'player',
                'payload' => ['defense_multiplier' => 0.5],
            ],
            [
                'sequence' => 6,
                'type' => 'damage_applied',
                'actor' => 'player',
                'target' => 'opponent',
                'payload' => [
                    'damage' => 6,
                    'hp_before' => 12,
                    'hp_after' => 6,
                    'defense_multiplier' => 0.5,
                    'is_immune' => false,
                ],
            ],
            [
                'sequence' => 7,
                'type' => 'attack_started',
                'actor' => 'opponent',
                'target' => 'player',
                'payload' => ['move_name' => 'Tackle', 'move_slug' => 'tackle', 'round' => 1],
            ],
            [
                'sequence' => 8,
                'type' => 'resources_spent',
                'actor' => 'opponent',
                'target' => 'opponent',
                'payload' => ['stamina_spent' => 5, 'pp_spent' => 1],
            ],
            [
                'sequence' => 9,
                'type' => 'defense_selected',
                'actor' => 'player',
                'target' => 'opponent',
                'payload' => ['defense' => 'endure', 'stamina_cost' => 0],
            ],
            [
                'sequence' => 10,
                'type' => 'damage_applied',
                'actor' => 'opponent',
                'target' => 'player',
                'payload' => [
                    'damage' => 4,
                    'hp_before' => 11,
                    'hp_after' => 7,
                    'is_immune' => false,
                ],
            ],
        ], $names);

        $this->assertSame('started', $log[0]['kind']);

        $playerHit = $log[1];
        $this->assertSame('exchange', $playerHit['kind']);
        $this->assertSame('Bulbasaur', $playerHit['attacker']['name']);
        $this->assertSame('Caterpie', $playerHit['defender']['name']);
        $this->assertSame('Vine Whip', $playerHit['attack']['name']);
        $this->assertSame(10, $playerHit['attack']['stamina_spent']);
        $this->assertSame(1, $playerHit['attack']['pp_spent']);
        $this->assertSame('block', $playerHit['defense']['type']);
        $this->assertSame(0.5, $playerHit['defense']['multiplier']);
        $this->assertSame('blocked', $playerHit['result']['hit']);
        $this->assertSame(6, $playerHit['result']['damage']);
        $this->assertSame(6, $playerHit['result']['hp_after']);

        $counter = $log[2];
        $this->assertSame('Caterpie', $counter['attacker']['name']);
        $this->assertSame('Bulbasaur', $counter['defender']['name']);
        $this->assertSame('Tackle', $counter['attack']['name']);
        $this->assertSame('endure', $counter['defense']['type']);
        $this->assertSame('hit', $counter['result']['hit']);
        $this->assertSame(7, $counter['result']['hp_after']);
    }

    public function test_finished_exchange_includes_faint_and_reward(): void
    {
        $builder = new CombatLogBuilder;
        $log = $builder->build([
            [
                'sequence' => 1,
                'type' => 'attack_started',
                'actor' => 'player',
                'target' => 'opponent',
                'payload' => ['move_name' => 'Vine Whip', 'round' => 4],
            ],
            [
                'sequence' => 2,
                'type' => 'resources_spent',
                'actor' => 'player',
                'target' => 'player',
                'payload' => ['stamina_spent' => 10, 'pp_spent' => 1],
            ],
            [
                'sequence' => 3,
                'type' => 'defense_selected',
                'actor' => 'opponent',
                'target' => 'player',
                'payload' => ['defense' => 'block', 'stamina_cost' => 10],
            ],
            [
                'sequence' => 4,
                'type' => 'damage_applied',
                'actor' => 'player',
                'target' => 'opponent',
                'payload' => ['damage' => 12, 'hp_before' => 12, 'hp_after' => 0, 'is_immune' => false],
            ],
            [
                'sequence' => 5,
                'type' => 'combatant_fainted',
                'actor' => 'opponent',
                'target' => 'player',
                'payload' => ['name' => 'Caterpie'],
            ],
            [
                'sequence' => 6,
                'type' => 'battle_finished',
                'actor' => null,
                'target' => null,
                'payload' => ['outcome' => 'win'],
            ],
        ], ['player' => 'Bulbasaur', 'opponent' => 'Caterpie'], ['xp_awarded' => 10]);

        $this->assertSame('Caterpie', $log[0]['fainted']);
        $this->assertSame('win', $log[0]['battle_outcome']);
        $this->assertSame('finished', $log[1]['kind']);
        $this->assertSame('win', $log[1]['outcome']);
        $this->assertSame(10, $log[1]['xp_awarded']);
    }

    public function test_builds_recover_skip_entries(): void
    {
        $builder = new CombatLogBuilder;

        $log = $builder->build([
            [
                'sequence' => 1,
                'type' => 'attack_skipped',
                'actor' => 'player',
                'target' => null,
                'payload' => ['reason' => 'recover_stamina', 'round' => 3],
            ],
            [
                'sequence' => 2,
                'type' => 'stamina_recovered',
                'actor' => 'player',
                'target' => 'player',
                'payload' => ['amount' => 15, 'before' => 5, 'after' => 20],
            ],
        ], ['player' => 'Bulbasaur', 'opponent' => 'Caterpie']);

        $this->assertCount(1, $log);
        $this->assertSame('recover_skip', $log[0]['kind']);
        $this->assertSame(3, $log[0]['round']);
        $this->assertSame('Bulbasaur', $log[0]['actor']['name']);
        $this->assertSame(15, $log[0]['stamina_gained']);
    }
}
