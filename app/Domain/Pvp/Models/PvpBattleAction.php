<?php

namespace App\Domain\Pvp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpBattleAction extends Model
{
    use HasUuids;

    protected $table = 'pvp_battle_actions';

    protected $fillable = [
        'battle_id',
        'actor_user_id',
        'action_type',
        'idempotency_key',
        'expected_version',
        'resolved_version',
        'input_snapshot',
        'result_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'expected_version' => 'integer',
            'resolved_version' => 'integer',
            'input_snapshot' => 'array',
            'result_snapshot' => 'array',
        ];
    }

    public function battle(): BelongsTo
    {
        return $this->belongsTo(PvpBattle::class, 'battle_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
