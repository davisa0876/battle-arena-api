<?php

namespace App\Domain\Pvp\Models;

use App\Domain\Battle\Models\Move;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpBattleCombatantMove extends Model
{
    protected $table = 'pvp_battle_combatant_moves';

    protected $fillable = [
        'pvp_battle_combatant_id',
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
            'slot' => 'integer',
            'pp_current' => 'integer',
            'pp_max' => 'integer',
            'stamina_cost' => 'integer',
            'move_snapshot' => 'array',
        ];
    }

    public function combatant(): BelongsTo
    {
        return $this->belongsTo(PvpBattleCombatant::class, 'pvp_battle_combatant_id');
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }
}
