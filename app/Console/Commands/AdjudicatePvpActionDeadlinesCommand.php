<?php

namespace App\Console\Commands;

use App\Application\Pvp\PvpActionDeadlineService;
use Illuminate\Console\Command;

final class AdjudicatePvpActionDeadlinesCommand extends Command
{
    protected $signature = 'pvp:adjudicate-action-deadlines {--limit= : Max due battles to process}';

    protected $description = 'Adjudicate overdue PvP action deadlines (no-op when turn timeout is disabled)';

    public function handle(PvpActionDeadlineService $deadlines): int
    {
        if (! $deadlines->adjudicationEnabled()) {
            $this->info('PvP turn timeout adjudication is disabled (PVP_TURN_TIMEOUT_ENABLED=false).');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $batch = is_numeric($limit)
            ? (int) $limit
            : (int) config('pvp.turn_timeout.batch_size', 50);

        $result = $deadlines->adjudicateDueBatch($batch);

        $this->info(sprintf(
            'Processed %d due row(s); timed out %d; skipped %d.',
            $result['processed'],
            $result['timed_out'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
