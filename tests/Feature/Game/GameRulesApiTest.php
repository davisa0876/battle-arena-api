<?php

namespace Tests\Feature\Game;

use Tests\TestCase;

class GameRulesApiTest extends TestCase
{
    public function test_game_rules_are_public_and_versioned(): void
    {
        $response = $this->getJson('/api/game/rules');

        $response->assertOk()
            ->assertJsonPath('data.rules_version', '4.0.0')
            ->assertJsonPath('data.features.auth', true)
            ->assertJsonPath('data.features.pve', true)
            ->assertJsonPath('data.features.evolution_execution', false)
            ->assertJsonPath('data.progression.xp_increment_per_level', 100)
            ->assertJsonPath('data.progression.curve.0.xp_to_advance', 100)
            ->assertJsonPath('data.progression.curve.1.xp_to_advance', 200)
            ->assertJsonPath('data.progression.curve.2.total_xp_required', 300)
            ->assertJsonPath('data.selection.stage_1.allowance_cap', 10)
            ->assertJsonPath('data.selection.stage_2.unlock_level', 10)
            ->assertJsonPath('data.selection.stage_3.unlock_level', null)
            ->assertJsonPath('data.battle.pvp_min_trainer_level', 6)
            ->assertJsonPath('data.battle.rewards.pve.win', 10)
            ->assertJsonPath('data.battle.rewards.pvp.win', 20)
            ->assertJsonPath('data.battle.playable', true)
            ->assertJsonPath('data.pve.playable', true)
            ->assertJsonPath('data.starters.creature_slugs.0', 'bulbasaur');
    }

    public function test_game_rules_respect_accept_language_header(): void
    {
        $this->withHeader('Accept-Language', 'fr')
            ->getJson('/api/game/rules')
            ->assertOk()
            ->assertHeader('Content-Language', 'fr');
    }
}
