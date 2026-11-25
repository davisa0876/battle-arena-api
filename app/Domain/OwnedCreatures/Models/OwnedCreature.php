<?php

namespace App\Domain\OwnedCreatures\Models;

use App\Domain\Creatures\Models\Creature;
use App\Models\User;
use Database\Factories\OwnedCreatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A creature owned by a trainer.
 *
 * `active_user_id` mirrors `user_id` while `is_active` is true and is NULL otherwise;
 * its UNIQUE index guarantees one active creature per trainer. It is kept in sync on save.
 */
class OwnedCreature extends Model
{
    /** @use HasFactory<OwnedCreatureFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'creature_id',
        'level',
        'total_xp',
        'is_active',
        'acquired_at',
    ];

    protected $attributes = [
        'level' => 1,
        'total_xp' => 0,
        'is_active' => false,
    ];

    protected static function booted(): void
    {
        static::saving(static function (self $owned): void {
            $owned->active_user_id = $owned->is_active ? $owned->user_id : null;
        });
    }

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'total_xp' => 'integer',
            'is_active' => 'boolean',
            'acquired_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creature(): BelongsTo
    {
        return $this->belongsTo(Creature::class);
    }

    public function evolutionHistory(): HasMany
    {
        return $this->hasMany(CreatureEvolutionHistory::class);
    }

    protected static function newFactory(): OwnedCreatureFactory
    {
        return OwnedCreatureFactory::new();
    }
}
