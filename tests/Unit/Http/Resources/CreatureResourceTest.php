<?php

namespace Tests\Unit\Http\Resources;

use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use App\Http\Resources\CreatureResource;
use App\Http\Resources\CreatureTypeResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreatureResourceTest extends TestCase
{
    private const IMAGE = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/25.png';

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function creature(array $overrides = []): Creature
    {
        $creature = new Creature(array_merge([
            'provider' => 'pokeapi',
            'external_id' => 25,
            'name' => 'Pikachu',
            'slug' => 'pikachu',
            'height_cm' => 40,
            'weight_g' => 6000,
            'base_hp' => 35,
            'base_attack' => 55,
            'base_defense' => 40,
            'base_special_attack' => 50,
            'base_special_defense' => 50,
            'base_speed' => 90,
            'image_ref' => self::IMAGE,
        ], $overrides));
        $creature->setAttribute('id', 7);

        return $creature;
    }

    /**
     * @param  list<array{id: int, name: string, slug: string}>  $types
     * @return Collection<int, CreatureType>
     */
    private function types(array $types): Collection
    {
        return collect($types)->map(function (array $row): CreatureType {
            $type = new CreatureType(['name' => $row['name'], 'slug' => $row['slug']]);
            $type->setAttribute('id', $row['id']);

            return $type;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function render(CreatureResource $resource): array
    {
        $json = $resource->toResponse(Request::create('/api/creatures/pikachu'))->getContent();

        return json_decode((string) $json, true)['data'];
    }

    #[Test]
    public function test_exposes_exact_public_key_set_in_order(): void
    {
        $creature = $this->creature();
        $creature->setRelation('types', $this->types([['id' => 1, 'name' => 'Electric', 'slug' => 'electric']]));

        $data = $this->render(new CreatureResource($creature));

        $this->assertSame(
            ['id', 'name', 'slug', 'height_cm', 'weight_g', 'image_ref', 'image_url', 'cry_latest_url', 'cry_legacy_url', 'cry_url', 'evolution_stage', 'is_owned', 'can_select', 'selection_action', 'selection_blocked_reason', 'types', 'base_stats'],
            array_keys($data),
        );
        $this->assertSame(
            ['hp', 'attack', 'defense', 'special_attack', 'special_defense', 'speed'],
            array_keys($data['base_stats']),
        );
    }

    #[Test]
    public function test_maps_scalar_values(): void
    {
        $data = $this->render(new CreatureResource($this->creature(['evolution_stage' => 1])));

        $this->assertSame(7, $data['id']);
        $this->assertSame('Pikachu', $data['name']);
        $this->assertSame('pikachu', $data['slug']);
        $this->assertSame(40, $data['height_cm']);
        $this->assertSame(6000, $data['weight_g']);
        $this->assertSame(1, $data['evolution_stage']);
    }

    #[Test]
    public function test_evolutions_are_omitted_when_relation_not_loaded(): void
    {
        $data = $this->render(new CreatureResource($this->creature()));

        $this->assertArrayNotHasKey('evolutions', $data);
        $this->assertArrayNotHasKey('evolves_from', $data);
    }

    #[Test]
    public function test_evolutions_are_included_when_relation_is_loaded(): void
    {
        $child = $this->creature(['name' => 'Raichu', 'slug' => 'raichu', 'evolution_stage' => 2]);
        $child->setAttribute('id', 8);
        $child->setRelation('types', $this->types([['id' => 1, 'name' => 'Electric', 'slug' => 'electric']]));
        $child->setRelation('evolutions', collect());

        $creature = $this->creature(['evolution_stage' => 1]);
        $creature->setRelation('types', $this->types([['id' => 1, 'name' => 'Electric', 'slug' => 'electric']]));
        $creature->setRelation('evolvesFrom', null);
        $creature->setRelation('evolutions', collect([$child]));

        $data = $this->render(new CreatureResource($creature));

        $this->assertNull($data['evolves_from']);
        $this->assertCount(1, $data['evolutions']);
        $this->assertSame('raichu', $data['evolutions'][0]['slug']);
        $this->assertSame(2, $data['evolutions'][0]['evolution_stage']);
    }

    #[Test]
    public function test_maps_base_stats_to_nested_object(): void
    {
        $data = $this->render(new CreatureResource($this->creature()));

        $this->assertSame([
            'hp' => 35,
            'attack' => 55,
            'defense' => 40,
            'special_attack' => 50,
            'special_defense' => 50,
            'speed' => 90,
        ], $data['base_stats']);
    }

    #[Test]
    public function test_null_image_yields_null_for_both_image_fields(): void
    {
        $data = $this->render(new CreatureResource($this->creature(['image_ref' => null])));

        $this->assertArrayHasKey('image_ref', $data);
        $this->assertArrayHasKey('image_url', $data);
        $this->assertNull($data['image_ref']);
        $this->assertNull($data['image_url']);
    }

    #[Test]
    public function test_image_ref_and_image_url_carry_the_same_value(): void
    {
        $data = $this->render(new CreatureResource($this->creature()));

        $this->assertSame(self::IMAGE, $data['image_ref']);
        $this->assertSame($data['image_ref'], $data['image_url']);
    }

    #[Test]
    public function test_null_height_and_weight_are_serialised_as_null(): void
    {
        $data = $this->render(new CreatureResource($this->creature(['height_cm' => null, 'weight_g' => null])));

        $this->assertArrayHasKey('height_cm', $data);
        $this->assertArrayHasKey('weight_g', $data);
        $this->assertNull($data['height_cm']);
        $this->assertNull($data['weight_g']);
    }

    #[Test]
    public function test_types_key_is_omitted_when_relation_not_loaded(): void
    {
        $data = $this->render(new CreatureResource($this->creature()));

        $this->assertArrayNotHasKey('types', $data);
    }

    #[Test]
    public function test_types_are_included_when_relation_is_loaded(): void
    {
        $creature = $this->creature();
        $creature->setRelation('types', $this->types([
            ['id' => 3, 'name' => 'Grass', 'slug' => 'grass'],
            ['id' => 4, 'name' => 'Poison', 'slug' => 'poison'],
        ]));

        $data = $this->render(new CreatureResource($creature));

        $this->assertSame([
            ['id' => 3, 'name' => 'Grass', 'slug' => 'grass'],
            ['id' => 4, 'name' => 'Poison', 'slug' => 'poison'],
        ], $data['types']);
    }

    #[Test]
    public function test_loaded_but_empty_types_render_as_empty_array(): void
    {
        $creature = $this->creature();
        $creature->setRelation('types', collect());

        $data = $this->render(new CreatureResource($creature));

        $this->assertArrayHasKey('types', $data);
        $this->assertSame([], $data['types']);
    }

    #[Test]
    public function test_does_not_expose_internal_provider_fields(): void
    {
        $creature = $this->creature();
        $creature->setRelation('types', $this->types([['id' => 1, 'name' => 'Electric', 'slug' => 'electric']]));

        $data = $this->render(new CreatureResource($creature));

        foreach (['provider', 'external_id', 'created_at', 'updated_at', 'base_hp', 'base_speed', 'pivot'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $data, "Unexpected key [{$forbidden}] leaked.");
        }
        $this->assertStringNotContainsString('pokeapi', (string) json_encode($data));
    }

    #[Test]
    public function test_type_items_do_not_leak_pivot_or_timestamps(): void
    {
        $type = $this->types([['id' => 1, 'name' => 'Electric', 'slug' => 'electric']])->first();
        $type->setRelation('pivot', (object) ['slot' => 1]);
        $creature = $this->creature();
        $creature->setRelation('types', collect([$type]));

        $data = $this->render(new CreatureResource($creature));

        $this->assertSame(['id', 'name', 'slug'], array_keys($data['types'][0]));
    }

    #[Test]
    public function test_collection_renders_every_creature(): void
    {
        $first = $this->creature(['name' => 'Alpha', 'slug' => 'alpha']);
        $second = $this->creature(['name' => 'Beta', 'slug' => 'beta']);

        $json = CreatureResource::collection(collect([$first, $second]))
            ->toResponse(Request::create('/api/creatures'))
            ->getContent();
        $decoded = json_decode((string) $json, true);

        $this->assertCount(2, $decoded['data']);
        $this->assertSame(['alpha', 'beta'], array_column($decoded['data'], 'slug'));
    }

    #[Test]
    public function test_creature_type_resource_exposes_only_id_name_and_slug(): void
    {
        $type = new CreatureType(['name' => 'Fire', 'slug' => 'fire']);
        $type->setAttribute('id', 9);

        $array = (new CreatureTypeResource($type))->toArray(Request::create('/'));

        $this->assertSame(['id' => 9, 'name' => 'Fire', 'slug' => 'fire'], $array);
    }

    #[Test]
    public function test_creature_type_resource_collection_preserves_order(): void
    {
        $json = CreatureTypeResource::collection($this->types([
            ['id' => 2, 'name' => 'Fire', 'slug' => 'fire'],
            ['id' => 1, 'name' => 'Water', 'slug' => 'water'],
        ]))->toResponse(Request::create('/api/creature-types'))->getContent();

        $decoded = json_decode((string) $json, true);

        $this->assertSame(['fire', 'water'], array_column($decoded['data'], 'slug'));
    }
}
