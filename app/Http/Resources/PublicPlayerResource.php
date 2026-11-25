<?php

namespace App\Http\Resources;

use App\Domain\Players\Models\PlayerProfile;
use App\Http\Resources\Concerns\BuildsAvatarUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What anyone may see about a trainer. Never exposes email or account identifiers beyond the handle.
 *
 * @property PlayerProfile $resource
 */
class PublicPlayerResource extends JsonResource
{
    use BuildsAvatarUrl;

    public function toArray(Request $request): array
    {
        $progress = $this->resource->user?->progress;

        return [
            'handle' => $this->resource->handle,
            'display_name' => $this->resource->display_name,
            'bio' => $this->resource->bio,
            'avatar_url' => $this->avatarUrl($this->resource),
            'collection_is_public' => $this->resource->collection_is_public,
            'trainer' => $progress === null ? null : new TrainerProgressResource($progress),
            'creatures' => $this->when(
                $this->resource->relationLoaded('collection'),
                fn () => OwnedCreatureResource::collection($this->resource->collection)->resolve($request),
            ),
        ];
    }
}
