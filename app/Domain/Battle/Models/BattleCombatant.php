<?php

namespace App\Domain\Battle\Models;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Immutable snapshot of a combatant's identity and effective stats; only hp/stamina change.
 */
class BattleCombatant extends Model
{
    protected $table = 'battle_combatants';

    protected $fillable = [
        'battle_id',
        'side',
        'creature_id',
        'owned_creature_id',
        'name',
        'slug',
        'image_ref',
        'cry_latest_url',
        'cry_legacy_url',
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
        return $this->belongsTo(PveBattle::class, 'battle_id');
    }

    public function creature(): BelongsTo
    {
        return $this->belongsTo(Creature::class);
    }

    public function ownedCreature(): BelongsTo
    {
        return $this->belongsTo(OwnedCreature::class);
    }

    public function moves(): HasMany
    {
        return $this->hasMany(BattleCombatantMove::class, 'battle_combatant_id')->orderBy('slot');
    }
}
