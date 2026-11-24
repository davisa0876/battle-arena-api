<?php

namespace App\Jobs;

use App\Application\Creatures\Services\CreatureImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ImportCreatureSpeciesChunkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * @param  list<int>  $speciesExternalIds
     */
    public function __construct(
        public array $speciesExternalIds,
    ) {}

    public function handle(CreatureImportService $importService): void
    {
        $summary = $importService->importSpeciesIds($this->speciesExternalIds, resolveEvolutions: false);

        Log::info('Creature species chunk imported.', [
            'count' => count($this->speciesExternalIds),
            'summary' => $summary,
        ]);
    }
}
