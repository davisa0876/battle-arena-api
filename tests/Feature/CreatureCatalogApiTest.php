<?php

namespace Tests\Feature;

use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreatureCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_pagination_is_deterministic(): void
    {
        Creature::factory()->create(['name' => 'Zorro', 'slug' => 'zorro']);
        Creature::factory()->create(['name' => 'Alpha', 'slug' => 'alpha']);
        Creature::factory()->create(['name' => 'Beta', 'slug' => 'beta']);

        $response = $this->getJson('/api/creatures?per_page=2');

        $response->assertOk()
            ->assertJsonPath('meta.per_page', 2)
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('data.0.name', 'Alpha')
            ->assertJsonPath('data.1.name', 'Beta');
    }

    public function test_catalog_search_by_name(): void
    {
        Creature::factory()->create(['name' => 'Flame Drake', 'slug' => 'flame-drake']);
        Creature::factory()->create(['name' => 'Aqua Sprite', 'slug' => 'aqua-sprite']);

        $this->getJson('/api/creatures?search=flame')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'flame-drake');
    }

    public function test_catalog_filter_by_type(): void
    {
        $fire = CreatureType::factory()->create(['name' => 'Fire', 'slug' => 'fire']);
        $water = CreatureType::factory()->create(['name' => 'Water', 'slug' => 'water']);

        $ember = Creature::factory()->create(['name' => 'Ember Fox', 'slug' => 'ember-fox']);
        $tide = Creature::factory()->create(['name' => 'Tide Pup', 'slug' => 'tide-pup']);

        $ember->types()->attach($fire->id, ['slot' => 1]);
        $tide->types()->attach($water->id, ['slot' => 1]);

        $this->getJson('/api/creatures?type=fire')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.slug', 'ember-fox')
            ->assertJsonPath('data.0.types.0.slug', 'fire');
    }

    public function test_creature_detail_returns_base_stats(): void
    {
        $type = CreatureType::factory()->create(['name' => 'Grass', 'slug' => 'grass']);
        $creature = Creature::factory()->create([
            'name' => 'Leaf Cub',
            'slug' => 'leaf-cub',
            'base_hp' => 55,
            'base_attack' => 60,
            'height_cm' => 80,
            'weight_g' => 12000,
            'image_ref' => null,
        ]);
        $creature->types()->attach($type->id, ['slot' => 1]);

        $this->getJson('/api/creatures/leaf-cub')
            ->assertOk()
            ->assertJsonPath('data.name', 'Leaf Cub')
            ->assertJsonPath('data.slug', 'leaf-cub')
            ->assertJsonPath('data.height_cm', 80)
            ->assertJsonPath('data.weight_g', 12000)
            ->assertJsonPath('data.base_stats.hp', 55)
            ->assertJsonPath('data.base_stats.attack', 60)
            ->assertJsonPath('data.types.0.slug', 'grass')
            ->assertJsonMissingPath('data.provider')
            ->assertJsonMissingPath('data.external_id');
    }

    public function test_missing_creature_returns_404(): void
    {
        $this->getJson('/api/creatures/does-not-exist')
            ->assertNotFound()
            ->assertJsonPath('message', 'Creature not found.');
    }

    public function test_invalid_query_validation(): void
    {
        $this->getJson('/api/creatures?per_page=999&type=NOT VALID')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page', 'type']);
    }

    public function test_creature_types_endpoint(): void
    {
        CreatureType::factory()->create(['name' => 'Water', 'slug' => 'water']);
        CreatureType::factory()->create(['name' => 'Fire', 'slug' => 'fire']);

        $this->getJson('/api/creature-types')
            ->assertOk()
            ->assertJsonPath('data.0.slug', 'fire')
            ->assertJsonPath('data.1.slug', 'water');
    }
}
