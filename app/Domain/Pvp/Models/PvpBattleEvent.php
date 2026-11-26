<?php

namespace App\Domain\Pvp\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpBattleEvent extends Model
{
    protected $table = 'pvp_battle_events';

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
        return $this->belongsTo(PvpBattle::class, 'battle_id');
    }
}
