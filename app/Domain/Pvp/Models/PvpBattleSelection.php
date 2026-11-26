<?php

namespace App\Domain\Pvp\Models;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PvpBattleSelection extends Model
{
    use HasUuids;

    protected $table = 'pvp_battle_selections';

    protected $fillable = [
        'room_id',
        'user_id',
        'owned_creature_id',
        'is_locked',
        'selected_at',
        'locked_at',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'selected_at' => 'datetime',
            'locked_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(PvpRoom::class, 'room_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ownedCreature(): BelongsTo
    {
        return $this->belongsTo(OwnedCreature::class, 'owned_creature_id');
    }
}
