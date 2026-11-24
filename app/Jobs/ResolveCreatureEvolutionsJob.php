<?php

namespace App\Jobs;

use App\Application\Creatures\Services\CreatureImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ResolveCreatureEvolutionsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function handle(CreatureImportService $importService): void
    {
        $importService->resolveEvolutionLinks();
        Log::info('Creature evolution links resolved.');
    }
}
