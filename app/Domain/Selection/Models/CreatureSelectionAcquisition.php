<?php

namespace App\Domain\Selection\Models;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Auditable record of a selection entitlement consumption.
 * `original_evolution_stage` is frozen at acquisition time.
 */
class CreatureSelectionAcquisition extends Model
{
    protected $fillable = [
        'user_id',
        'owned_creature_id',
        'creature_id',
        'original_evolution_stage',
        'source',
        'trainer_level_at_acquisition',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'original_evolution_stage' => 'integer',
            'trainer_level_at_acquisition' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ownedCreature(): BelongsTo
    {
        return $this->belongsTo(OwnedCreature::class);
    }

    public function creature(): BelongsTo
    {
        return $this->belongsTo(Creature::class);
    }
}
