<?php

namespace App\Domain\Battle\Models;

use App\Domain\Creatures\Models\Creature;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreatureLearnableMove extends Model
{
    protected $table = 'creature_learnable_moves';

    protected $fillable = [
        'creature_id',
        'move_id',
        'version_group',
        'learn_level',
        'learn_method',
    ];

    protected function casts(): array
    {
        return [
            'creature_id' => 'integer',
            'move_id' => 'integer',
            'learn_level' => 'integer',
        ];
    }

    public function creature(): BelongsTo
    {
        return $this->belongsTo(Creature::class);
    }

    public function move(): BelongsTo
    {
        return $this->belongsTo(Move::class);
    }
}
