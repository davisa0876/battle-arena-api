<?php

namespace App\Domain\Creatures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Playable creature variety persisted in the local catalog.
 *
 * This row represents a concrete combat variety (stats + types), not an abstract
 * biological species. Alternate forms can be added later as additional rows that
 * share a future species_id (or a provider-specific external form id) without
 * changing the public catalog contract.
 */
class Creature extends Model
{
    /** @use HasFactory<\Database\Factories\CreatureFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'external_id',
        'name',
        'slug',
        'height_cm',
        'weight_g',
        'base_hp',
        'base_attack',
        'base_defense',
        'base_special_attack',
        'base_special_defense',
        'base_speed',
        'image_ref',
    ];

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'height_cm' => 'integer',
            'weight_g' => 'integer',
            'base_hp' => 'integer',
            'base_attack' => 'integer',
            'base_defense' => 'integer',
            'base_special_attack' => 'integer',
            'base_special_defense' => 'integer',
            'base_speed' => 'integer',
        ];
    }

    public function types(): BelongsToMany
    {
        return $this->belongsToMany(CreatureType::class, 'creature_type')
            ->withPivot('slot')
            ->withTimestamps()
            ->orderByPivot('slot');
    }

    protected static function newFactory(): \Database\Factories\CreatureFactory
    {
        return \Database\Factories\CreatureFactory::new();
    }
}
