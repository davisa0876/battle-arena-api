<?php

namespace App\Models;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Domain\Players\Models\PlayerProfile;
use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\Models\PlayerXpEvent;
use App\Domain\Selection\Models\CreatureSelectionAcquisition;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function profile(): HasOne
    {
        return $this->hasOne(PlayerProfile::class);
    }

    public function progress(): HasOne
    {
        return $this->hasOne(PlayerProgress::class);
    }

    public function ownedCreatures(): HasMany
    {
        return $this->hasMany(OwnedCreature::class);
    }

    public function xpEvents(): HasMany
    {
        return $this->hasMany(PlayerXpEvent::class);
    }

    public function selectionAcquisitions(): HasMany
    {
        return $this->hasMany(CreatureSelectionAcquisition::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
