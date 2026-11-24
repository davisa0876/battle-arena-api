<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Domain\Creatures\Models\Creature
 */
class CreatureResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'height_cm' => $this->height_cm,
            'weight_g' => $this->weight_g,
            'image_ref' => $this->image_ref,
            'types' => CreatureTypeResource::collection($this->whenLoaded('types')),
            'base_stats' => [
                'hp' => $this->base_hp,
                'attack' => $this->base_attack,
                'defense' => $this->base_defense,
                'special_attack' => $this->base_special_attack,
                'special_defense' => $this->base_special_defense,
                'speed' => $this->base_speed,
            ],
        ];
    }
}
