<?php

namespace App\Domain\Battle\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One cell of the versioned elemental chart: (ruleset, attacking type, defending type) => multiplier.
 */
class TypeEffectiveness extends Model
{
    protected $table = 'type_effectiveness';

    protected $fillable = [
        'ruleset',
        'attacking_type_slug',
        'defending_type_slug',
        'multiplier',
    ];

    protected function casts(): array
    {
        return [
            'multiplier' => 'float',
        ];
    }
}
