<?php

namespace App\Domain\Battle\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Normalized combat move imported for a pinned ruleset / version group.
 */
class Move extends Model
{
    protected $table = 'moves';

    protected $fillable = [
        'provider',
        'external_id',
        'slug',
        'name',
        'power',
        'accuracy',
        'pp',
        'damage_class',
        'type_slug',
        'priority',
        'version_group',
        'is_mvp_supported',
    ];

    protected function casts(): array
    {
        return [
            'external_id' => 'integer',
            'power' => 'integer',
            'accuracy' => 'integer',
            'pp' => 'integer',
            'priority' => 'integer',
            'is_mvp_supported' => 'boolean',
        ];
    }

    public function learnableBy(): HasMany
    {
        return $this->hasMany(CreatureLearnableMove::class);
    }
}
