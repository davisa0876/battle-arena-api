<?php

namespace App\Domain\Progression;

use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\Models\PlayerXpEvent;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Authoritative trainer XP curve and idempotent XP awards.
 *
 * Arithmetic progression (config-driven):
 *   xp_to_next(level)     = xp_increment_per_level * level
 *   total_xp_for_level(L) = 50 * (L - 1) * L
 *
 * Trainer level is account progression — not evolution_stage and not owned-creature level.
 * There is intentionally no public HTTP entry point that grants XP.
 */
class TrainerProgressionService
{
    public function __construct(
        private readonly Config $config,
        private readonly DatabaseManager $db,
    ) {}

    public function maxLevel(): int
    {
        return max(1, (int) $this->config->get('game.progression.max_level', 100));
    }

    public function xpIncrementPerLevel(): int
    {
        return max(1, (int) $this->config->get('game.progression.xp_increment_per_level', 100));
    }

    /**
     * XP required to advance from `$level` to `$level + 1`.
     */
    public function xpToAdvance(int $level): int
    {
        if ($level < 1) {
            throw new InvalidArgumentException('Level must be at least 1.');
        }

        return $this->xpIncrementPerLevel() * $level;
    }

    /**
     * Cumulative XP required to REACH `$level` from level 1.
     * Closed form: 50 * (L - 1) * L when increment is 100.
     */
    public function totalXpForLevel(int $level): int
    {
        if ($level < 1) {
            throw new InvalidArgumentException('Level must be at least 1.');
        }

        if ($level === 1) {
            return 0;
        }

        $increment = $this->xpIncrementPerLevel();

        // Sum_{k=1}^{L-1} (increment * k) = increment * (L-1)*L / 2
        return (int) (($increment * ($level - 1) * $level) / 2);
    }

    public function levelForTotalXp(int $totalXp): int
    {
        $totalXp = max(0, $totalXp);
        $level = 1;
        $max = $this->maxLevel();

        while ($level < $max && $totalXp >= $this->totalXpForLevel($level + 1)) {
            $level++;
        }

        return $level;
    }

    public function snapshot(PlayerProgress $progress): TrainerProgressSnapshot
    {
        $totalXp = max(0, (int) $progress->total_xp);
        $level = $this->levelForTotalXp($totalXp);
        $levelStart = $this->totalXpForLevel($level);
        $xpIntoLevel = $totalXp - $levelStart;

        if ($level >= $this->maxLevel()) {
            return new TrainerProgressSnapshot(
                level: $level,
                totalXp: $totalXp,
                xpIntoLevel: $xpIntoLevel,
                xpToNextLevel: null,
                xpForNextLevel: null,
                progressPercent: 100.0,
                isMaxLevel: true,
            );
        }

        $span = $this->xpToAdvance($level);

        return new TrainerProgressSnapshot(
            level: $level,
            totalXp: $totalXp,
            xpIntoLevel: $xpIntoLevel,
            xpToNextLevel: $span - $xpIntoLevel,
            xpForNextLevel: $span,
            progressPercent: round(($xpIntoLevel / $span) * 100, 1),
            isMaxLevel: false,
        );
    }

    /**
     * Awards XP once per idempotency key. A repeated key returns the original event untouched.
     *
     * @throws InvalidArgumentException when the amount is not positive or the key belongs to another user
     */
    public function awardXp(
        User $user,
        int $amount,
        string $sourceType,
        ?string $sourceReference,
        string $idempotencyKey,
    ): XpAwardResult {
        if ($amount <= 0) {
            throw new InvalidArgumentException('XP amount must be greater than zero.');
        }

        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('An idempotency key is required.');
        }

        try {
            return $this->db->transaction(
                fn (): XpAwardResult => $this->applyAward($user, $amount, $sourceType, $sourceReference, $idempotencyKey)
            );
        } catch (UniqueConstraintViolationException) {
            return $this->db->transaction(function () use ($user, $idempotencyKey): XpAwardResult {
                $progress = $this->lockedProgress($user);

                return $this->duplicateResult($user, $idempotencyKey, $progress);
            });
        }
    }

    private function applyAward(
        User $user,
        int $amount,
        string $sourceType,
        ?string $sourceReference,
        string $idempotencyKey,
    ): XpAwardResult {
        $progress = $this->lockedProgress($user);

        if (PlayerXpEvent::query()->where('idempotency_key', $idempotencyKey)->exists()) {
            return $this->duplicateResult($user, $idempotencyKey, $progress);
        }

        $event = PlayerXpEvent::query()->create([
            'user_id' => $user->id,
            'amount' => $amount,
            'source_type' => $sourceType,
            'source_reference' => $sourceReference,
            'idempotency_key' => $idempotencyKey,
        ]);

        $previousLevel = $this->levelForTotalXp((int) $progress->total_xp);
        $progress->total_xp = (int) $progress->total_xp + $amount;
        $progress->level = $this->levelForTotalXp($progress->total_xp);
        $progress->save();

        return new XpAwardResult($event, $progress, $progress->level - $previousLevel, false);
    }

    private function duplicateResult(User $user, string $idempotencyKey, PlayerProgress $progress): XpAwardResult
    {
        $existing = PlayerXpEvent::query()->where('idempotency_key', $idempotencyKey)->firstOrFail();

        if ((int) $existing->user_id !== (int) $user->id) {
            throw new InvalidArgumentException('Idempotency key already used by another trainer.');
        }

        return new XpAwardResult($existing, $progress, 0, true);
    }

    private function lockedProgress(User $user): PlayerProgress
    {
        $progress = PlayerProgress::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();

        if ($progress !== null) {
            return $progress;
        }

        return PlayerProgress::query()->create(['user_id' => $user->id]);
    }
}
