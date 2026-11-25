<?php

namespace App\Domain\Creatures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Playable creature variety persisted in the local catalog.
 *
 * Evolution: `evolves_from_id` is null for stage-1 (base) forms shown in the catalog.
 * `evolutions()` lists the immediate next forms for the detail screen.
 */
class Creature extends Model
{
    /** @use HasFactory<\Database\Factories\CreatureFactory> */
    use HasFactory;

    protected $fillable = [
        'provider',
        'external_id',
        'species_external_id',
        'evolves_from_species_external_id',
        'evolves_from_id',
        'evolution_stage',
        'is_default_form',
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
        'cry_latest_url',
        'cry_legacy_url',
    ];

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'species_external_id' => 'integer',
            'evolves_from_species_external_id' => 'integer',
            'evolves_from_id' => 'integer',
            'evolution_stage' => 'integer',
            'is_default_form' => 'boolean',
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

    public function evolvesFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'evolves_from_id');
    }

    public function evolutions(): HasMany
    {
        return $this->hasMany(self::class, 'evolves_from_id')
            ->orderBy('evolution_stage')
            ->orderBy('name')
            ->orderBy('id');
    }

    public function scopeBaseForms($query)
    {
        return $query
            ->where('is_default_form', true)
            ->whereNull('evolves_from_id');
    }

    protected static function newFactory(): \Database\Factories\CreatureFactory
    {
        return \Database\Factories\CreatureFactory::new();
    }
}
