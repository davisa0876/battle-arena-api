<?php

namespace App\Domain\Pvp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpBattleReward extends Model
{
    use HasUuids;

    protected $table = 'pvp_battle_rewards';

    protected $fillable = [
        'battle_id',
        'user_id',
        'outcome',
        'xp_awarded',
        'trainer_levels_gained',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'xp_awarded' => 'integer',
            'trainer_levels_gained' => 'integer',
        ];
    }

    public function battle(): BelongsTo
    {
        return $this->belongsTo(PvpBattle::class, 'battle_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
