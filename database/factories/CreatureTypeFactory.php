<?php

namespace Database\Factories;

use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CreatureType>
 */
class CreatureTypeFactory extends Factory
{
    protected $model = CreatureType::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'name' => Str::title($name),
            'slug' => Str::slug($name),
        ];
    }
}
