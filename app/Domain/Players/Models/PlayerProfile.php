<?php

namespace App\Domain\Players\Models;

use App\Models\User;
use Database\Factories\PlayerProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Public-facing trainer identity. `handle` is always stored lowercase.
 */
class PlayerProfile extends Model
{
    /** @use HasFactory<PlayerProfileFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'handle',
        'display_name',
        'bio',
        'avatar_path',
        'avatar_disk',
        'collection_is_public',
    ];

    protected function casts(): array
    {
        return [
            'collection_is_public' => 'boolean',
        ];
    }

    public static function normalizeHandle(string $handle): string
    {
        return mb_strtolower(trim($handle));
    }

    public function setHandleAttribute(string $value): void
    {
        $this->attributes['handle'] = self::normalizeHandle($value);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function hasAvatar(): bool
    {
        return $this->avatar_path !== null && $this->avatar_disk !== null;
    }

    protected static function newFactory(): PlayerProfileFactory
    {
        return PlayerProfileFactory::new();
    }
}
