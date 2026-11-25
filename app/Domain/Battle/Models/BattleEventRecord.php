<?php

namespace App\Domain\Battle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persisted, immutable, ordered domain fact (table `battle_events`).
 */
class BattleEventRecord extends Model
{
    protected $table = 'battle_events';

    protected $fillable = [
        'battle_id',
        'sequence',
        'type',
        'actor',
        'target',
        'payload',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function battle(): BelongsTo
    {
        return $this->belongsTo(PveBattle::class, 'battle_id');
    }
}
