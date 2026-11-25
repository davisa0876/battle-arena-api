<?php

namespace Tests\Feature\Game;

use Tests\TestCase;

class GameRulesContentTest extends TestCase
{
    public function test_rules_expose_the_full_feature_matrix(): void
    {
        $this->getJson('/api/game/rules')
            ->assertOk()
            ->assertJsonPath('data.features.auth', true)
            ->assertJsonPath('data.features.profiles', true)
            ->assertJsonPath('data.features.collection', true)
            ->assertJsonPath('data.features.starter_selection', true)
            ->assertJsonPath('data.features.creature_selection', true)
            ->assertJsonPath('data.features.pve', true)
            ->assertJsonPath('data.features.pvp', false)
            ->assertJsonPath('data.features.evolution_execution', false);
    }

    public function test_rules_include_formula_curve_selection_battle_and_avatar_limits(): void
    {
        $response = $this->getJson('/api/game/rules')->assertOk();

        $response->assertJsonPath('data.progression.xp_increment_per_level', 100)
            ->assertJsonPath('data.progression.xp_growth', null)
            ->assertJsonPath('data.progression.curve.1.xp_to_advance', 200)
            ->assertJsonPath('data.progression.curve.2.xp_to_advance', 300)
            ->assertJsonPath('data.progression.curve.2.total_xp_required', 300)
            ->assertJsonCount(20, 'data.progression.curve')
            ->assertJsonPath('data.selection.samples.9.stage_1_allowance', 10)
            ->assertJsonPath('data.selection.samples.9.stage_2_allowance', 1)
            ->assertJsonPath('data.battle.pve_min_trainer_level', 1)
            ->assertJsonPath('data.battle.pvp_min_trainer_level', 6)
            ->assertJsonPath('data.starters.creature_slugs', ['bulbasaur', 'charmander', 'squirtle', 'pikachu', 'eevee'])
            ->assertJsonPath('data.avatar.max_upload_kb', 2048)
            ->assertJsonPath('data.avatar.max_dimension', 1024)
            ->assertJsonPath('data.avatar.output_dimension', 512);
    }
}
