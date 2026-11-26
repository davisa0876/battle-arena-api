<?php

namespace App\Domain\Pvp\Models;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PvpBattleCombatant extends Model
{
    protected $table = 'pvp_battle_combatants';

    protected $fillable = [
        'battle_id',
        'user_id',
        'side',
        'creature_id',
        'owned_creature_id',
        'name',
        'slug',
        'image_ref',
        'level',
        'hp_current',
        'hp_max',
        'stamina_current',
        'stamina_max',
        'stats_snapshot',
        'types_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'hp_current' => 'integer',
            'hp_max' => 'integer',
            'stamina_current' => 'integer',
            'stamina_max' => 'integer',
            'stats_snapshot' => 'array',
            'types_snapshot' => 'array',
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

    public function creature(): BelongsTo
    {
        return $this->belongsTo(Creature::class);
    }

    public function ownedCreature(): BelongsTo
    {
        return $this->belongsTo(OwnedCreature::class, 'owned_creature_id');
    }

    public function moves(): HasMany
    {
        return $this->hasMany(PvpBattleCombatantMove::class, 'pvp_battle_combatant_id');
    }
}
