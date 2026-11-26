<?php

namespace Database\Seeders;

use App\Application\Battles\CompletePveBattleService;
use App\Application\Battles\SeedCombatDataService;
use App\Domain\Battle\BattleRewardPolicy;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Players\Models\PlayerProfile;
use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\Models\PlayerXpEvent;
use App\Domain\Progression\TrainerProgressionService;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local/dev only: two trainers ready for private PvP smoke (level >= 6).
 *
 * Resolves the real owner accounts (not only @test.local stubs), then seeds a
 * PvE win/loss career whose XP awards exactly reach the PvP unlock level.
 *
 * php artisan db:seed --class=PvpPreviewTrainersSeeder
 */
class PvpPreviewTrainersSeeder extends Seeder
{
    private const SEED_MARK = 'pvp_preview';

    private const PASSWORD = 'Test@12345';

    /**
     * @var list<array{
     *   handle:string,
     *   aliases:list<string>,
     *   emails:list<string>,
     *   display_names:list<string>,
     *   display_name:string,
     *   creature_slug:string
     * }>
     */
    private array $trainers = [
        [
            'handle' => 'ahahahah',
            'aliases' => ['ahahaha', 'ahahahah'],
            'emails' => ['davi@test.com', 'ahahahah@test.local'],
            'display_names' => ['ahahahah', 'ahahaha'],
            'display_name' => 'Ahahahah',
            'creature_slug' => 'charmander',
        ],
        [
            'handle' => 'bombom',
            'aliases' => ['bombom', 'picka'],
            'emails' => ['davisa0876@gmail.com', 'bombom@test.local'],
            'display_names' => ['bombom'],
            'display_name' => 'bombom',
            'creature_slug' => 'squirtle',
        ],
    ];

    public function run(): void
    {
        if (! app()->environment(['local', 'development', 'testing'])) {
            $this->command?->error('PvpPreviewTrainersSeeder is local/dev/testing only.');

            return;
        }

        app(SeedCombatDataService::class)->seed();

        $progression = app(TrainerProgressionService::class);
        $complete = app(CompletePveBattleService::class);
        $targetLevel = max(6, (int) config('game.battle.pvp_min_trainer_level', 6));
        $targetXp = $progression->totalXpForLevel($targetLevel);
        $winXp = (int) config('game.battle.rewards.pve.win', 10);
        $lossXp = (int) config('game.battle.rewards.pve.loss', 5);

        foreach ($this->trainers as $spec) {
            $summary = DB::transaction(function () use ($spec, $targetLevel, $targetXp, $winXp, $lossXp, $progression, $complete): array {
                [$user, $profile, $created] = $this->resolveTrainer($spec);

                $profile->handle = PlayerProfile::normalizeHandle($spec['handle']);
                $profile->display_name = $spec['display_name'];
                $profile->save();

                // Never overwrite password/email of an account that already existed.
                if ($created) {
                    $user->forceFill([
                        'name' => $spec['display_name'],
                        'password' => Hash::make(self::PASSWORD),
                        'email_verified_at' => now(),
                    ])->save();
                } else {
                    $user->forceFill([
                        'name' => $spec['display_name'],
                    ])->save();
                }

                $owned = $this->ensureOwnedCreature($user, $spec['creature_slug']);
                $this->resetPreviewCareer($user);
                $career = $this->seedPveCareerToTarget(
                    $user,
                    $owned,
                    $targetXp,
                    $winXp,
                    $lossXp,
                    $complete,
                );

                $progress = PlayerProgress::query()->where('user_id', $user->id)->firstOrFail();
                $snapshot = $progression->snapshot($progress);

                return [
                    'handle' => $profile->handle,
                    'email' => $user->email,
                    'level' => (int) $snapshot->level,
                    'total_xp' => (int) $snapshot->totalXp,
                    'wins' => $career['wins'],
                    'losses' => $career['losses'],
                    'give_ups' => $career['give_ups'],
                    'creature' => $spec['creature_slug'],
                    'owned_creature_id' => (int) $owned->id,
                    'created' => $created,
                ];
            });

            $this->command?->info(sprintf(
                'PvP ready: handle=%s email=%s level=%d xp=%d W/L/G=%d/%d/%d creature=%s owned_id=%d %s',
                $summary['handle'],
                $summary['email'],
                $summary['level'],
                $summary['total_xp'],
                $summary['wins'],
                $summary['losses'],
                $summary['give_ups'],
                $summary['creature'],
                $summary['owned_creature_id'],
                $summary['created'] ? 'password='.self::PASSWORD : 'password=unchanged',
            ));
        }
        $this->command?->warn('features.pvp remains false; authenticated private lobby preview only.');
        $this->command?->warn('Invite flow: ahahahah invites bombom (or the reverse) on /pvp.');
    }

    /**
     * @param  array{
     *   handle:string,
     *   aliases:list<string>,
     *   emails:list<string>,
     *   display_names:list<string>,
     *   display_name:string,
     *   creature_slug:string
     * }  $spec
     * @return array{0: User, 1: PlayerProfile, 2: bool} Third value is true when the user was created by this run.
     */
    private function resolveTrainer(array $spec): array
    {
        $handle = PlayerProfile::normalizeHandle($spec['handle']);
        $aliases = array_values(array_unique(array_map(
            static fn (string $h): string => PlayerProfile::normalizeHandle($h),
            [$handle, ...$spec['aliases']],
        )));
        $emails = array_values(array_unique(array_map(
            static fn (string $e): string => mb_strtolower(trim($e)),
            $spec['emails'],
        )));
        $displayNames = array_values(array_unique(array_map(
            static fn (string $n): string => mb_strtolower(trim($n)),
            [$spec['display_name'], ...$spec['display_names']],
        )));

        // Prefer real owner emails first, then display_name, then handle aliases.
        // Avoid binding the preferred handle to a leftover @test.local stub.
        $user = null;
        $created = false;
        foreach ($emails as $email) {
            $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();
            if ($user !== null) {
                break;
            }
        }

        if ($user === null) {
            $profileByName = PlayerProfile::query()
                ->where(function ($q) use ($displayNames): void {
                    foreach ($displayNames as $name) {
                        $q->orWhereRaw('LOWER(display_name) = ?', [$name]);
                    }
                })
                ->whereHas('user', function ($q) use ($emails): void {
                    // Prefer non-stub accounts when multiple display_name matches exist.
                    $q->where(function ($inner) use ($emails): void {
                        foreach ($emails as $email) {
                            $inner->orWhereRaw('LOWER(email) = ?', [$email]);
                        }
                        $inner->orWhereRaw("LOWER(email) NOT LIKE '%@test.local'");
                    });
                })
                ->first();

            if ($profileByName !== null) {
                $user = $profileByName->user()->firstOrFail();
            }
        }

        if ($user === null) {
            $profileByHandle = PlayerProfile::query()
                ->whereIn('handle', $aliases)
                ->whereHas('user', function ($q): void {
                    $q->whereRaw("LOWER(email) NOT LIKE '%@test.local'");
                })
                ->first();

            if ($profileByHandle === null) {
                $profileByHandle = PlayerProfile::query()
                    ->whereIn('handle', $aliases)
                    ->first();
            }

            if ($profileByHandle !== null) {
                $user = $profileByHandle->user()->firstOrFail();
            }
        }

        if ($user === null) {
            $created = true;
            $user = User::query()->create([
                'name' => $spec['display_name'],
                'email' => $emails[0],
                'password' => Hash::make(self::PASSWORD),
                'email_verified_at' => now(),
            ]);
        }

        $this->releaseHandleConflicts($handle, (int) $user->id);

        $profile = PlayerProfile::query()->firstOrNew(['user_id' => $user->id]);
        $profile->handle = $handle;
        $profile->display_name = $spec['display_name'];
        $profile->bio = $profile->bio ?? 'PvP preview trainer';
        $profile->collection_is_public = $profile->collection_is_public ?? false;
        $profile->save();

        return [$user, $profile, $created];
    }

    private function releaseHandleConflicts(string $desiredHandle, int $keepUserId): void
    {
        $conflicts = PlayerProfile::query()
            ->where('handle', $desiredHandle)
            ->where('user_id', '!=', $keepUserId)
            ->get();

        foreach ($conflicts as $conflict) {
            $conflict->handle = PlayerProfile::normalizeHandle(
                $desiredHandle.'_old_'.$conflict->user_id
            );
            $conflict->save();
        }
    }

    private function ensureOwnedCreature(User $user, string $creatureSlug): OwnedCreature
    {
        $creature = Creature::query()->where('slug', $creatureSlug)->first();
        if ($creature === null) {
            throw new \RuntimeException('Missing creature slug '.$creatureSlug.'. Import catalog first.');
        }

        OwnedCreature::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['is_active' => false, 'active_user_id' => null]);

        $owned = OwnedCreature::query()
            ->where('user_id', $user->id)
            ->where('creature_id', $creature->id)
            ->first();

        if ($owned === null) {
            $owned = new OwnedCreature([
                'user_id' => $user->id,
                'creature_id' => $creature->id,
                'level' => 5,
                'total_xp' => 0,
                'acquired_at' => now(),
            ]);
        }

        $owned->level = max(5, (int) $owned->level);
        $owned->is_active = true;
        $owned->save();

        return $owned;
    }

    /**
     * Drop previous preview-seeded PvE career + XP events, then rebuild from a clean base.
     * Keeps non-preview XP events and recomputes progress from them before re-seeding.
     */
    private function resetPreviewCareer(User $user): void
    {
        $previewBattleIds = PveBattle::query()
            ->where('user_id', $user->id)
            ->where('rules_snapshot->seed', self::SEED_MARK)
            ->pluck('id')
            ->all();

        if ($previewBattleIds !== []) {
            PlayerXpEvent::query()
                ->where('user_id', $user->id)
                ->where('source_type', 'battle_pve')
                ->whereIn('source_reference', $previewBattleIds)
                ->delete();

            PveBattle::query()->whereIn('id', $previewBattleIds)->delete();
        }

        // Local smoke accounts: rebuild the whole career so level 6 is earned from seeded fights.
        PlayerXpEvent::query()->where('user_id', $user->id)->delete();
        PveBattle::query()->where('user_id', $user->id)->delete();

        $progress = PlayerProgress::query()->firstOrNew(['user_id' => $user->id]);
        $progress->total_xp = 0;
        $progress->level = 1;
        $progress->save();
    }

    /**
     * @return array{wins:int, losses:int, give_ups:int}
     */
    private function seedPveCareerToTarget(
        User $user,
        OwnedCreature $owned,
        int $targetXp,
        int $winXp,
        int $lossXp,
        CompletePveBattleService $complete,
    ): array {
        if ($winXp <= 0 || $lossXp <= 0) {
            throw new \RuntimeException('PvE reward config must be positive for preview career seeding.');
        }

        // Exact XP path to target: maximize wins, fill remainder with losses.
        $wins = intdiv($targetXp, $winXp);
        $remainder = $targetXp - ($wins * $winXp);
        $losses = 0;
        if ($remainder > 0) {
            if ($remainder % $lossXp !== 0) {
                // Borrow one win and cover with losses so total XP lands exactly.
                $wins = max(0, $wins - 1);
                $remainder = $targetXp - ($wins * $winXp);
            }
            if ($remainder % $lossXp !== 0) {
                throw new \RuntimeException(sprintf(
                    'Cannot compose exact XP %d from win=%d loss=%d.',
                    $targetXp,
                    $winXp,
                    $lossXp,
                ));
            }
            $losses = intdiv($remainder, $lossXp);
        }

        // Slightly more realistic record: convert some wins into win+loss pairs that preserve XP.
        // (win + loss) = winXp + lossXp; replace with nothing XP-wise unless we keep both counts.
        // Keep math exact: 120W/60L for default 10/5 → 1500.
        if ($winXp === 10 && $lossXp === 5 && $targetXp === 1500 && $wins === 150 && $losses === 0) {
            $wins = 120;
            $losses = 60;
        }

        $giveUps = 4;
        $rulesVersion = (string) config('game.pve.rules_version', 'pve-0.1');
        $sequence = 0;

        $user->loadMissing('progress');

        for ($i = 0; $i < $wins; $i++) {
            $this->finishPreviewBattle(
                $user,
                $owned,
                $rulesVersion,
                BattleRewardPolicy::OUTCOME_WIN,
                PveBattle::END_REASON_COMBAT,
                ++$sequence,
                $complete,
            );
        }

        for ($i = 0; $i < $losses; $i++) {
            $this->finishPreviewBattle(
                $user,
                $owned,
                $rulesVersion,
                BattleRewardPolicy::OUTCOME_LOSS,
                PveBattle::END_REASON_COMBAT,
                ++$sequence,
                $complete,
            );
        }

        for ($i = 0; $i < $giveUps; $i++) {
            $this->finishPreviewBattle(
                $user,
                $owned,
                $rulesVersion,
                BattleRewardPolicy::OUTCOME_LOSS,
                PveBattle::END_REASON_ABANDONED,
                ++$sequence,
                $complete,
            );
        }

        $progress = PlayerProgress::query()->where('user_id', $user->id)->firstOrFail();
        if ((int) $progress->total_xp !== $targetXp) {
            throw new \RuntimeException(sprintf(
                'Preview career XP mismatch for user %d: got %d expected %d.',
                $user->id,
                $progress->total_xp,
                $targetXp,
            ));
        }

        return [
            'wins' => $wins,
            'losses' => $losses,
            'give_ups' => $giveUps,
        ];
    }

    private function finishPreviewBattle(
        User $user,
        OwnedCreature $owned,
        string $rulesVersion,
        string $outcome,
        string $endReason,
        int $sequence,
        CompletePveBattleService $complete,
    ): void {
        $battle = PveBattle::query()->create([
            'user_id' => $user->id,
            'owned_creature_id' => $owned->id,
            'status' => PveBattle::STATUS_FINISHED,
            'phase' => 'finished',
            'round' => max(1, random_int(1, 8)),
            'version' => 2,
            'rules_version' => $rulesVersion,
            'rules_snapshot' => [
                'seed' => self::SEED_MARK,
                'n' => $sequence,
            ],
            'difficulty' => 'normal',
            'outcome' => $outcome,
            'end_reason' => $endReason,
            'rng_seed' => Str::lower(Str::random(32)),
            'rng_cursor' => 0,
            'reward_processed' => false,
            'finished_at' => now()->subMinutes(max(1, 2000 - $sequence)),
        ]);

        $user->unsetRelation('progress');
        $complete->completeIfNeeded($user->fresh(['progress']), $battle->fresh());
    }
}
