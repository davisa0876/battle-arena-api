<?php

namespace App\Http\Resources;

use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Progression\TrainerProgressionService;
use App\Domain\Selection\SelectionEntitlementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PlayerProgress $resource
 */
class TrainerProgressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $snapshot = app(TrainerProgressionService::class)->snapshot($this->resource);
        $user = $this->resource->relationLoaded('user')
            ? $this->resource->user
            : $this->resource->user()->first();

        $payload = [
            'level' => $snapshot->level,
            'total_xp' => $snapshot->totalXp,
            'xp_into_level' => $snapshot->xpIntoLevel,
            'xp_for_next_level' => $snapshot->xpForNextLevel,
            'xp_to_next_level' => $snapshot->xpToNextLevel,
            'progress_percent' => $snapshot->progressPercent,
            'is_max_level' => $snapshot->isMaxLevel,
        ];

        // Selection quotas and PvP unlock are private to the authenticated owner.
        $viewer = $request->user();
        if ($user !== null && $viewer !== null && (int) $viewer->id === (int) $user->id) {
            $entitlements = app(SelectionEntitlementService::class);
            $payload['selection_allowances'] = $entitlements->allowancesFor($user, $this->resource);
            $payload['pvp_level_unlocked'] = $entitlements->pvpLevelUnlocked($user, $this->resource);
        }

        return $payload;
    }
}
