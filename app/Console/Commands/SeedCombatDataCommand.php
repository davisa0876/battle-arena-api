<?php

namespace App\Console\Commands;

use App\Application\Battles\SeedCombatDataService;
use Illuminate\Console\Command;

final class SeedCombatDataCommand extends Command
{
    protected $signature = 'combat:seed-mvp';

    protected $description = 'Idempotently seed MVP PvE moves, type chart, and learnable loadouts from fixtures';

    public function handle(SeedCombatDataService $seed): int
    {
        $result = $seed->seed();
        $this->info(sprintf(
            'Seeded %d moves, %d type rows, %d learnable links across %d creatures.',
            $result['moves'],
            $result['type_rows'],
            $result['learnable_moves'],
            $result['creatures'],
        ));

        return self::SUCCESS;
    }
}
