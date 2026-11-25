<?php

namespace App\Domain\Progression\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable audit row for a trainer XP award. `idempotency_key` is globally unique.
 */
class PlayerXpEvent extends Model
{
    protected $fillable = [
        'user_id',
        'amount',
        'source_type',
        'source_reference',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
