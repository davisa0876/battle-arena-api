<?php

namespace Database\Factories;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OwnedCreature>
 */
class OwnedCreatureFactory extends Factory
{
    protected $model = OwnedCreature::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'creature_id' => Creature::factory(),
            'level' => 1,
            'total_xp' => 0,
            'is_active' => false,
            'acquired_at' => now(),
        ];
    }

    public function active(): static
    {
        return $this->state(fn (): array => ['is_active' => true]);
    }
}
