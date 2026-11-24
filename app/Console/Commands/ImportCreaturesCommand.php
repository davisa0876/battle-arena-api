<?php

namespace App\Console\Commands;

use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Application\Creatures\Services\CreatureImportService;
use App\Jobs\ImportCreatureSpeciesChunkJob;
use App\Jobs\ResolveCreatureEvolutionsJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Bus;

class ImportCreaturesCommand extends Command
{
    protected $signature = 'creatures:import
                            {--ids= : Optional comma-separated pokemon external IDs (defaults to the starter set)}
                            {--all : Import the full national dex (all species default forms)}
                            {--sync : Run immediately instead of queueing jobs}
                            {--chunk=40 : Species IDs per queued job when using --all}';

    protected $description = 'Import creatures from the configured provider into PostgreSQL (with evolution metadata)';

    public function handle(CreatureImportService $importService): int
    {
        if ($this->option('all')) {
            return $this->importAll($importService);
        }

        return $this->importIds($importService);
    }

    private function importAll(CreatureImportService $importService): int
    {
        $this->info(__('creatures.import_started'));
        $this->line('Mode: full national dex (species default forms)');

        try {
            $speciesIds = $importService->listNationalDexSpeciesIds();
        } catch (CreatureProviderException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $chunkSize = max(1, (int) $this->option('chunk'));
        $chunks = array_chunk($speciesIds, $chunkSize);
        $this->line('Species: '.count($speciesIds).' · chunks: '.count($chunks).' · size: '.$chunkSize);

        if ($this->option('sync')) {
            $totals = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
            $bar = $this->output->createProgressBar(count($chunks));
            $bar->start();

            foreach ($chunks as $chunk) {
                try {
                    $summary = $importService->importSpeciesIds($chunk, resolveEvolutions: false);
                    $totals['imported'] += $summary['imported'];
                    $totals['updated'] += $summary['updated'];
                    $totals['skipped'] += $summary['skipped'];
                    $totals['failed'] += $summary['failed'];
                    $totals['errors'] = [...$totals['errors'], ...$summary['errors']];
                } catch (CreatureProviderException $exception) {
                    $this->newLine();
                    $this->error($exception->getMessage());

                    return self::FAILURE;
                }
                $bar->advance();
            }

            $bar->finish();
            $this->newLine(2);
            $this->line('Resolving evolution links…');
            $importService->resolveEvolutionLinks();

            return $this->reportSummary($totals);
        }

        $jobs = array_map(
            static fn (array $chunk): ImportCreatureSpeciesChunkJob => new ImportCreatureSpeciesChunkJob($chunk),
            $chunks,
        );
        $jobs[] = new ResolveCreatureEvolutionsJob;

        Bus::chain($jobs)->dispatch();

        $this->info('Queued '.count($chunks).' import jobs + evolution resolve. Run: php artisan queue:work');

        return self::SUCCESS;
    }

    private function importIds(CreatureImportService $importService): int
    {
        $idsOption = $this->option('ids');
        $externalIds = null;

        if (is_string($idsOption) && trim($idsOption) !== '') {
            $externalIds = array_values(array_filter(array_map(
                static fn (string $value): int => (int) trim($value),
                explode(',', $idsOption),
            ), static fn (int $id): bool => $id > 0));

            if ($externalIds === []) {
                $this->error(__('creatures.import_invalid_ids'));

                return self::FAILURE;
            }
        }

        $this->info(__('creatures.import_started'));
        $this->line(
            'IDs: '.implode(
                ', ',
                $externalIds ?? CreatureImportService::DEFAULT_EXTERNAL_IDS,
            ),
        );

        try {
            $summary = $importService->import($externalIds);
        } catch (CreatureProviderException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->reportSummary($summary);
    }

    /**
     * @param  array{imported: int, updated: int, skipped: int, failed: int, errors: list<string>}  $summary
     */
    private function reportSummary(array $summary): int
    {
        $this->table(
            ['Metric', 'Count'],
            [
                ['Imported', $summary['imported']],
                ['Updated', $summary['updated']],
                ['Skipped (incomplete)', $summary['skipped']],
                ['Failed', $summary['failed']],
            ],
        );

        foreach (array_slice($summary['errors'], 0, 30) as $error) {
            $this->warn($error);
        }
        if (count($summary['errors']) > 30) {
            $this->warn('… and '.(count($summary['errors']) - 30).' more errors');
        }

        if ($summary['imported'] === 0 && $summary['updated'] === 0) {
            $this->error(__('creatures.import_none'));

            return self::FAILURE;
        }

        $this->info(__('creatures.import_finished'));

        return self::SUCCESS;
    }
}
