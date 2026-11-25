<?php

namespace App\Domain\Battle\Models;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Authoritative persisted PvE match. `version` increments on every successful transition.
 * `rng_seed` / `rng_cursor` never leave the server.
 */
class PveBattle extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FINISHED = 'finished';

    public const END_REASON_COMBAT = 'combat';

    public const END_REASON_ABANDONED = 'abandoned';

    protected $table = 'pve_battles';

    protected $fillable = [
        'user_id',
        'owned_creature_id',
        'status',
        'phase',
        'round',
        'version',
        'rules_version',
        'rules_snapshot',
        'difficulty',
        'outcome',
        'end_reason',
        'rng_seed',
        'rng_cursor',
        'opponent_pending_action',
        'reward_processed',
        'xp_awarded',
        'trainer_levels_gained',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'round' => 'integer',
            'version' => 'integer',
            'rng_cursor' => 'integer',
            'rules_snapshot' => 'array',
            'opponent_pending_action' => 'array',
            'reward_processed' => 'boolean',
            'xp_awarded' => 'integer',
            'trainer_levels_gained' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ownedCreature(): BelongsTo
    {
        return $this->belongsTo(OwnedCreature::class);
    }

    public function combatants(): HasMany
    {
        return $this->hasMany(BattleCombatant::class, 'battle_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(BattleAction::class, 'battle_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BattleEventRecord::class, 'battle_id')->orderBy('sequence');
    }
}
