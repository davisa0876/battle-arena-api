<?php

namespace App\Http\Resources;

use App\Application\Battles\PveBattleStatsService;
use App\Application\Pvp\PvpBattleStatsService;
use App\Http\Resources\Concerns\BuildsAvatarUrl;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in trainer's own account view. Expects `profile`, `progress` and
 * `owned_creatures_exists` to be loaded (see PlayerAccountService).
 *
 * @property User $resource
 */
class AuthenticatedUserResource extends JsonResource
{
    use BuildsAvatarUrl;

    public function toArray(Request $request): array
    {
        $profile = $this->resource->relationLoaded('profile') ? $this->resource->profile : null;
        $progress = $this->resource->relationLoaded('progress') ? $this->resource->progress : null;

        return [
            'id' => $this->resource->id,
            'email' => $this->resource->email,
            'email_verified' => $this->resource->hasVerifiedEmail(),
            'email_verified_at' => $this->resource->email_verified_at?->toIso8601String(),
            'profile' => $profile === null ? null : [
                'handle' => $profile->handle,
                'display_name' => $profile->display_name,
                'bio' => $profile->bio,
                'avatar_url' => $this->avatarUrl($profile),
                'collection_is_public' => $profile->collection_is_public,
            ],
            'trainer' => $progress === null ? null : new TrainerProgressResource($progress),
            'pve_record' => app(PveBattleStatsService::class)->forUser($this->resource),
            'pvp_record' => app(PvpBattleStatsService::class)->forUser($this->resource),
            'onboarding' => [
                'starter_selected' => (bool) ($this->resource->owned_creatures_exists ?? false),
            ],
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
