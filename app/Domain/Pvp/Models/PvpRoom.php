<?php

namespace App\Domain\Pvp\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PvpRoom extends Model
{
    use HasUuids;

    public const STATUS_WAITING_FOR_GUEST = 'waiting_for_guest';

    public const STATUS_READY = 'ready';

    public const STATUS_IN_BATTLE = 'in_battle';

    public const STATUS_CLOSED = 'closed';

    public const CLOSED_CANCELLED = 'cancelled';

    public const CLOSED_DECLINED = 'declined';

    public const CLOSED_EXPIRED = 'expired';

    public const CLOSED_LEFT = 'left';

    public const CLOSED_BATTLE_COMPLETED = 'battle_completed';

    protected $table = 'pvp_rooms';

    protected $fillable = [
        'host_user_id',
        'status',
        'version',
        'closed_reason',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(PvpRoomMember::class, 'room_id');
    }

    public function activeMembers(): HasMany
    {
        return $this->members()->whereNull('left_at');
    }

    public function invitation(): HasOne
    {
        return $this->hasOne(PvpInvitation::class, 'room_id')->latestOfMany();
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(PvpInvitation::class, 'room_id');
    }

    public function selections(): HasMany
    {
        return $this->hasMany(PvpBattleSelection::class, 'room_id');
    }

    public function battle(): HasOne
    {
        return $this->hasOne(PvpBattle::class, 'room_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== self::STATUS_CLOSED;
    }

    public function isInBattle(): bool
    {
        return $this->status === self::STATUS_IN_BATTLE;
    }
}
