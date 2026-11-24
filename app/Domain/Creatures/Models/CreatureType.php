<?php

namespace App\Domain\Creatures\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CreatureType extends Model
{
    /** @use HasFactory<\Database\Factories\CreatureTypeFactory> */
    use HasFactory;

    protected $table = 'creature_types';

    protected $fillable = [
        'name',
        'slug',
    ];

    public function creatures(): BelongsToMany
    {
        return $this->belongsToMany(Creature::class, 'creature_type')
            ->withPivot('slot')
            ->withTimestamps();
    }

    protected static function newFactory(): \Database\Factories\CreatureTypeFactory
    {
        return \Database\Factories\CreatureTypeFactory::new();
    }
}
