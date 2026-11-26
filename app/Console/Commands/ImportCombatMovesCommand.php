<?php

namespace App\Console\Commands;

use App\Application\Battles\CombatMovesImportService;
use Illuminate\Console\Command;
use Laravel\Telescope\Telescope;

/**
 * Import combat moves + learnsets for the pinned version group (default: scarlet-violet).
 *
 * Live path hits PokéAPI (rate-limited via PokeApiClient). Use --fixture for offline/CI.
 * Type chart is always seeded from the Gen 9 fixture.
 */
class ImportCombatMovesCommand extends Command
{
    protected $signature = 'combat:import-moves
                            {--fixture : Seed from local MVP fixtures (no network)}
                            {--limit= : Cap how many catalog creatures to process (live import only)}';

    protected $description = 'Import combat moves and learnsets from PokéAPI (or --fixture for offline seed)';

    public function handle(CombatMovesImportService $importer): int
    {
        // Bulk PokéAPI traffic + Telescope HTTP entries blow the default 128M quickly.
        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '512M');
        }
        if (class_exists(Telescope::class)) {
            Telescope::stopRecording();
        }

        if ($this->option('fixture')) {
            $this->info('Seeding combat catalog from local fixtures…');
            $result = $importer->importFromFixtures();
            $this->info(sprintf(
                'Fixtures: %d moves, %d type-chart rows, %d learnable links across %d creatures.',
                $result['moves'],
                $result['type_rows'],
                $result['learnable_moves'],
                $result['creatures'],
            ));

            return self::SUCCESS;
        }

        $limitRaw = $this->option('limit');
        $limit = $limitRaw !== null && $limitRaw !== '' ? max(1, (int) $limitRaw) : null;

        $this->info('Importing combat moves/learnsets from PokéAPI…');
        if ($limit !== null) {
            $this->comment("Creature limit: {$limit}");
        }

        $result = $importer->importFromPokeApi($limit);

        $this->info(sprintf(
            'PokéAPI: %d creatures, %d moves upserted, %d learnable links, %d type-chart rows (%d skipped).',
            $result['creatures'],
            $result['moves_upserted'],
            $result['learnable_links'],
            $result['type_rows'],
            $result['skipped_creatures'],
        ));

        if ($result['errors'] !== []) {
            $this->warn(sprintf('First %d error(s):', count($result['errors'])));
            foreach ($result['errors'] as $error) {
                $this->line('  - '.$error);
            }
        }

        return $result['skipped_creatures'] > 0 && $result['creatures'] === $result['skipped_creatures']
            ? self::FAILURE
            : self::SUCCESS;
    }
}
