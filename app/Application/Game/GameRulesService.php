<?php

namespace App\Application\Game;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Progression\TrainerProgressionService;
use App\Domain\Selection\CreatureSelectionPolicy;
use Illuminate\Contracts\Config\Repository as Config;

class GameRulesService
{
    public function __construct(
        private readonly Config $config,
        private readonly TrainerProgressionService $progression,
        private readonly CreatureSelectionPolicy $selection,
        private readonly BattleEligibilityPolicy $battleEligibility,
        private readonly BattleRewardPolicy $battleRewards,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sampleLevels = max(1, (int) $this->config->get('game.progression.curve_sample_levels', 20));
        $curve = [];
        $selectionSamples = [];

        for ($level = 1; $level <= $sampleLevels; $level++) {
            $curve[] = [
                'level' => $level,
                'xp_to_advance' => $this->progression->xpToAdvance($level),
                'total_xp_required' => $this->progression->totalXpForLevel($level),
            ];
            $selectionSamples[] = [
                'trainer_level' => $level,
                'stage_1_allowance' => $this->selection->stage1Allowance($level),
                'stage_2_allowance' => $this->selection->stage2Allowance($level),
            ];
        }

        return [
            'rules_version' => (string) $this->config->get('game.rules_version'),
            'version' => (string) $this->config->get('game.rules_version'),
            'features' => (array) $this->config->get('game.features', []),
            'trainer' => [
                'starting_level' => (int) $this->config->get('game.progression.starting_level', 1),
                'starting_xp' => (int) $this->config->get('game.progression.starting_xp', 0),
                'xp_increment_per_level' => $this->progression->xpIncrementPerLevel(),
                'max_level' => $this->progression->maxLevel(),
                'formula' => (string) $this->config->get('game.progression.formula'),
                'curve' => $curve,
            ],
            'progression' => [
                'starting_level' => (int) $this->config->get('game.progression.starting_level', 1),
                'starting_xp' => (int) $this->config->get('game.progression.starting_xp', 0),
                'xp_increment_per_level' => $this->progression->xpIncrementPerLevel(),
                // Backward-compatible aliases for older clients during transition.
                'xp_base' => $this->progression->xpIncrementPerLevel(),
                'xp_growth' => null,
                'max_level' => $this->progression->maxLevel(),
                'formula' => (string) $this->config->get('game.progression.formula'),
                'curve' => $curve,
            ],
            'selection' => [
                'stage_1' => (array) $this->config->get('game.selection.stage_1', []),
                'stage_2' => (array) $this->config->get('game.selection.stage_2', []),
                'stage_3' => (array) $this->config->get('game.selection.stage_3', []),
                'samples' => $selectionSamples,
            ],
            'battle' => [
                'pve_min_trainer_level' => $this->battleEligibility->pveMinTrainerLevel(),
                'pvp_min_trainer_level' => $this->battleEligibility->pvpMinTrainerLevel(),
                'rewards' => $this->battleRewards->allRewards(),
                'playable' => false,
            ],
            'starters' => [
                'creature_slugs' => array_values((array) $this->config->get('game.starters.creature_slugs', [])),
            ],
            'avatar' => [
                'max_upload_kb' => (int) $this->config->get('game.avatar.max_upload_kb'),
                'max_dimension' => (int) $this->config->get('game.avatar.max_dimension'),
                'output_dimension' => (int) $this->config->get('game.avatar.output_dimension'),
                'allowed_mime_types' => array_values((array) $this->config->get('game.avatar.allowed_mime_types', [])),
            ],
        ];
    }
}
