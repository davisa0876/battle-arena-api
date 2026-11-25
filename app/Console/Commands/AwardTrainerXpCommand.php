<?php

namespace App\Console\Commands;

use App\Domain\Progression\TrainerProgressionService;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Str;
use InvalidArgumentException;

class AwardTrainerXpCommand extends Command
{
    protected $signature = 'trainer:award-xp
        {user : User id, email or handle}
        {amount : XP to award (positive integer)}
        {--source=dev : Source type recorded on the XP event}
        {--key= : Idempotency key (a random one is used when omitted)}';

    protected $description = 'Development only: award trainer XP (local and testing environments)';

    public function handle(TrainerProgressionService $progression): int
    {
        if (! App::environment(['local', 'testing'])) {
            $this->error('This command is only available in the local and testing environments.');

            return self::FAILURE;
        }

        $user = $this->resolveUser((string) $this->argument('user'));
        if ($user === null) {
            $this->error('User not found.');

            return self::FAILURE;
        }

        $amount = filter_var($this->argument('amount'), FILTER_VALIDATE_INT);
        if ($amount === false) {
            $this->error('Amount must be an integer.');

            return self::FAILURE;
        }

        $key = (string) ($this->option('key') ?: 'dev:'.Str::uuid());

        try {
            $result = $progression->awardXp($user, $amount, (string) $this->option('source'), null, $key);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result->wasDuplicate) {
            $this->warn("Key [{$key}] was already applied; nothing awarded.");
        } else {
            $this->info("Awarded {$amount} XP.");
        }

        $this->line(sprintf('Trainer is level %d with %d total XP.', $result->progress->level, $result->progress->total_xp));

        return self::SUCCESS;
    }

    private function resolveUser(string $identifier): ?User
    {
        if (ctype_digit($identifier)) {
            return User::query()->find((int) $identifier);
        }

        if (str_contains($identifier, '@')) {
            return User::query()->where('email', User::normalizeEmail($identifier))->first();
        }

        return User::query()
            ->whereHas('profile', fn ($query) => $query->where('handle', mb_strtolower($identifier)))
            ->first();
    }
}
