<?php

namespace App\Domain\OwnedCreatures\Models;

use App\Domain\Creatures\Models\Creature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * History only: evolution execution is not implemented yet.
 */
class CreatureEvolutionHistory extends Model
{
    protected $table = 'creature_evolution_history';

    protected $fillable = [
        'owned_creature_id',
        'from_creature_id',
        'to_creature_id',
        'evolved_at',
    ];

    protected function casts(): array
    {
        return [
            'evolved_at' => 'datetime',
        ];
    }

    public function ownedCreature(): BelongsTo
    {
        return $this->belongsTo(OwnedCreature::class);
    }

    public function fromCreature(): BelongsTo
    {
        return $this->belongsTo(Creature::class, 'from_creature_id');
    }

    public function toCreature(): BelongsTo
    {
        return $this->belongsTo(Creature::class, 'to_creature_id');
    }
}
