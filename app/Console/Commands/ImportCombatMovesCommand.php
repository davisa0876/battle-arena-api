<?php

namespace App\Console\Commands;

use App\Application\Battles\SeedCombatDataService;
use Illuminate\Console\Command;

/**
 * Stub for the future PokéAPI move/type/learnset import.
 *
 * TODO: implement a PokeApi-backed importer behind a contract (rate-limited, idempotent, pinned to
 * game.pve.version_group). Until then this command only runs the fixture seed so environments stay usable.
 */
class ImportCombatMovesCommand extends Command
{
    protected $signature = 'combat:import-moves';

    protected $description = 'Import combat moves (live PokéAPI import not implemented yet; falls back to the MVP fixture seed)';

    public function handle(SeedCombatDataService $seeder): int
    {
        $this->warn('Live PokéAPI import is not implemented yet; seeding from local fixtures instead.');

        $result = $seeder->seed();
        $this->info(sprintf('Seeded %d moves and %d type-chart rows.', $result['moves'], $result['type_rows']));

        return self::SUCCESS;
    }
}
