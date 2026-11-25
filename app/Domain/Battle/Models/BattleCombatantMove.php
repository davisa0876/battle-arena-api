<?php

namespace App\Domain\Battle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equipped move snapshot. `move_snapshot` is frozen at match start; only `pp_current` changes.
 */
class BattleCombatantMove extends Model
{
    protected $table = 'battle_combatant_moves';

    protected $fillable = [
        'battle_combatant_id',
        'move_id',
        'slot',
        'pp_current',
        'pp_max',
        'stamina_cost',
        'move_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'move_id' => 'integer',
            'slot' => 'integer',
            'pp_current' => 'integer',
            'pp_max' => 'integer',
            'stamina_cost' => 'integer',
            'move_snapshot' => 'array',
        ];
    }

    public function combatant(): BelongsTo
    {
        return $this->belongsTo(BattleCombatant::class, 'battle_combatant_id');
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }
}
