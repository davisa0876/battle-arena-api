<?php

namespace App\Http\Resources;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property OwnedCreature $resource
 */
class OwnedCreatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'level' => $this->resource->level,
            'total_xp' => $this->resource->total_xp,
            'is_active' => $this->resource->is_active,
            'acquired_at' => $this->resource->acquired_at?->toIso8601String(),
            'creature' => CreatureResource::make($this->whenLoaded('creature')),
            'evolution' => $this->when(
                $this->resource->relationLoaded('evolutionOptions'),
                fn (): array => [
                    'execution_enabled' => (bool) config('game.features.evolution_execution'),
                    'options' => $this->resource->evolutionOptions->map(fn (array $option): array => [
                        'creature' => CreatureResource::make($option['creature'])->resolve($request),
                        'eligible' => $option['eligible'],
                        'locked' => $option['locked'],
                        'reason_code' => $option['reason_code'],
                        'reason' => __('api.evolution_reasons.'.$option['reason_code']),
                    ])->values()->all(),
                ],
            ),
        ];
    }
}
