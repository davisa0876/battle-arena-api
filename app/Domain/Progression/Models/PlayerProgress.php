<?php

namespace App\Domain\Progression\Models;

use App\Models\User;
use Database\Factories\PlayerProgressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlayerProgress extends Model
{
    /** @use HasFactory<PlayerProgressFactory> */
    use HasFactory;

    protected $table = 'player_progress';

    protected $fillable = [
        'user_id',
        'level',
        'total_xp',
    ];

    protected $attributes = [
        'level' => 1,
        'total_xp' => 0,
    ];

    protected function casts(): array
    {
        return [
            'level' => 'integer',
            'total_xp' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): PlayerProgressFactory
    {
        return PlayerProgressFactory::new();
    }
}
