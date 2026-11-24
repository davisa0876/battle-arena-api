<?php

namespace App\Console\Commands;

use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Application\Creatures\Services\CreatureImportService;
use Illuminate\Console\Command;

class ImportCreaturesCommand extends Command
{
    protected $signature = 'creatures:import
                            {--ids= : Optional comma-separated external provider IDs (defaults to the documented starter set)}';

    protected $description = 'Import a bounded creature catalog from the configured external provider into PostgreSQL';

    public function handle(CreatureImportService $importService): int
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

        $this->table(
            ['Metric', 'Count'],
            [
                ['Imported', $summary['imported']],
                ['Updated', $summary['updated']],
                ['Skipped (incomplete)', $summary['skipped']],
                ['Failed', $summary['failed']],
            ],
        );

        foreach ($summary['errors'] as $error) {
            $this->warn($error);
        }

        if ($summary['imported'] === 0 && $summary['updated'] === 0) {
            $this->error(__('creatures.import_none'));

            return self::FAILURE;
        }

        $this->info(__('creatures.import_finished'));

        return self::SUCCESS;
    }
}
