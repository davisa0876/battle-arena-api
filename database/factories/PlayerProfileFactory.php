<?php

namespace Database\Factories;

use App\Domain\Players\Models\PlayerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerProfile>
 */
class PlayerProfileFactory extends Factory
{
    protected $model = PlayerProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'handle' => 'trainer_'.fake()->unique()->numerify('######'),
            'display_name' => fake()->name(),
            'bio' => null,
            'avatar_path' => null,
            'avatar_disk' => null,
            'collection_is_public' => false,
        ];
    }

    public function publicCollection(): static
    {
        return $this->state(fn (): array => ['collection_is_public' => true]);
    }
}
