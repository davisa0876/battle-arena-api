<?php

namespace App\Http\Resources;

use App\Application\Creatures\Services\CatalogSelectionPresenter;
use App\Domain\Creatures\Support\TrustedCryUrl;
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
        $imageUrl = $this->image_ref;
        $cryLatest = TrustedCryUrl::normalize($this->cry_latest_url);
        $cryLegacy = TrustedCryUrl::normalize($this->cry_legacy_url);
        $selection = app(CatalogSelectionPresenter::class)->forCreature($request, $this->resource);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'height_cm' => $this->height_cm,
            'weight_g' => $this->weight_g,
            // Compatibility for existing clients.
            'image_ref' => $imageUrl,
            // Explicit public field for display URLs persisted in PostgreSQL.
            'image_url' => $imageUrl,
            'cry_latest_url' => $cryLatest,
            'cry_legacy_url' => $cryLegacy,
            'cry_url' => TrustedCryUrl::prefer($cryLatest, $cryLegacy),
            'evolution_stage' => (int) ($this->evolution_stage ?? 1),
            // Ownership / selection eligibility — authoritative for catalog UI buttons.
            'is_owned' => $selection['selection_blocked_reason'] === 'owned',
            'can_select' => $selection['can_select'],
            'selection_action' => $selection['selection_action'],
            'selection_blocked_reason' => $selection['selection_blocked_reason'],
            'types' => CreatureTypeResource::collection($this->whenLoaded('types')),
            'base_stats' => [
                'hp' => $this->base_hp,
                'attack' => $this->base_attack,
                'defense' => $this->base_defense,
                'special_attack' => $this->base_special_attack,
                'special_defense' => $this->base_special_defense,
                'speed' => $this->base_speed,
            ],
            'evolves_from' => $this->when(
                $this->relationLoaded('evolvesFrom'),
                fn () => $this->evolvesFrom === null
                    ? null
                    : (new self($this->evolvesFrom))->resolve()
            ),
            'evolutions' => $this->when(
                $this->relationLoaded('evolutions'),
                fn () => self::collection($this->evolutions)->resolve()
            ),
        ];
    }
}
