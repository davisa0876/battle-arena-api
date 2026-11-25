<?php

namespace App\Domain\Battle\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Idempotency ledger: one row per accepted player command (unique per battle + key).
 */
class BattleAction extends Model
{
    use HasUuids;

    public const ACTOR_PLAYER = 'player';

    public const TYPE_ATTACK = 'attack';

    public const TYPE_DEFEND = 'defend';

    protected $table = 'battle_actions';

    protected $fillable = [
        'battle_id',
        'actor',
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
        return $this->belongsTo(PveBattle::class, 'battle_id');
    }
}
