<?php

namespace Database\Factories;

use App\Domain\Progression\Models\PlayerProgress;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlayerProgress>
 */
class PlayerProgressFactory extends Factory
{
    protected $model = PlayerProgress::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'level' => 1,
            'total_xp' => 0,
        ];
    }
}
