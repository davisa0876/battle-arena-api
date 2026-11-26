<?php

namespace App\Domain\Pvp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PvpBattle extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FINISHED = 'finished';

    public const PHASE_AWAITING_ATTACK = 'awaiting_attack';

    public const PHASE_AWAITING_DEFENSE = 'awaiting_defense';

    public const PHASE_FINISHED = 'finished';

    public const END_REASON_COMBAT = 'combat';

    public const END_REASON_FORFEIT = 'forfeit';

    public const END_REASON_TIMEOUT = 'timeout';

    protected $table = 'pvp_battles';

    protected $fillable = [
        'room_id',
        'status',
        'phase',
        'turn_number',
        'version',
        'host_user_id',
        'guest_user_id',
        'current_attacker_user_id',
        'current_defender_user_id',
        'pending_attack_snapshot',
        'rng_seed',
        'rng_cursor',
        'rules_version',
        'rules_snapshot',
        'reward_snapshot',
        'winner_user_id',
        'loser_user_id',
        'forfeiting_user_id',
        'timed_out_user_id',
        'end_reason',
        'rewards_processed',
        'last_event_sequence',
        'started_at',
        'finished_at',
        'action_deadline_at',
        'deadline_actor_user_id',
        'deadline_phase',
        'deadline_version',
    ];

    protected function casts(): array
    {
        return [
            'turn_number' => 'integer',
            'version' => 'integer',
            'pending_attack_snapshot' => 'array',
            'rng_cursor' => 'integer',
            'rules_snapshot' => 'array',
            'reward_snapshot' => 'array',
            'rewards_processed' => 'boolean',
            'last_event_sequence' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'action_deadline_at' => 'datetime',
            'deadline_version' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PvpRoom::class, 'room_id');
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(User::class, 'guest_user_id');
    }

    public function combatants(): HasMany
    {
        return $this->hasMany(PvpBattleCombatant::class, 'battle_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PvpBattleEvent::class, 'battle_id');
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PvpBattleAction::class, 'battle_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(PvpBattleReward::class, 'battle_id');
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED || $this->phase === self::PHASE_FINISHED;
    }

    public function isParticipant(int $userId): bool
    {
        return (int) $this->host_user_id === $userId || (int) $this->guest_user_id === $userId;
    }
}
