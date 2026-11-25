<?php

namespace Database\Factories;

use App\Domain\Creatures\Models\Creature;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Creature>
 */
class CreatureFactory extends Factory
{
    protected $model = Creature::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        $externalId = fake()->unique()->numberBetween(1, 50_000);

        return [
            'provider' => 'pokeapi',
            'external_id' => $externalId,
            'species_external_id' => $externalId,
            'evolves_from_species_external_id' => null,
            'evolves_from_id' => null,
            'evolution_stage' => 1,
            'is_default_form' => true,
            'name' => Str::title($name),
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('###'),
            'height_cm' => fake()->numberBetween(20, 250),
            'weight_g' => fake()->numberBetween(1000, 100_000),
            'base_hp' => fake()->numberBetween(30, 150),
            'base_attack' => fake()->numberBetween(30, 150),
            'base_defense' => fake()->numberBetween(30, 150),
            'base_special_attack' => fake()->numberBetween(30, 150),
            'base_special_defense' => fake()->numberBetween(30, 150),
            'base_speed' => fake()->numberBetween(30, 150),
            'image_ref' => null,
            'cry_latest_url' => null,
            'cry_legacy_url' => null,
        ];
    }

    public function evolutionOf(Creature $parent, int $stage = 2): static
    {
        return $this->state(fn (): array => [
            'evolves_from_id' => $parent->id,
            'evolves_from_species_external_id' => $parent->species_external_id,
            'evolution_stage' => $stage,
            'is_default_form' => true,
        ]);
    }
}
