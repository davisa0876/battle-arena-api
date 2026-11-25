<?php

namespace Tests\Support;

use App\Domain\Creatures\Models\Creature;
use App\Models\User;

trait MakesPlayers
{
    /**
     * Headers that make Sanctum treat the request as coming from the stateful web SPA.
     *
     * @return array<string, string>
     */
    protected function webHeaders(): array
    {
        return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $user
     * @param  array<string, mixed>  $progress
     */
    protected function makePlayer(array $profile = [], array $user = [], array $progress = []): User
    {
        return User::factory()->withPlayer($profile, $progress)->create($user);
    }

    /**
     * @return array{Authorization: string}
     */
    protected function bearerFor(User $user, string $device = 'phpunit'): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken($device)->plainTextToken];
    }

    protected function seedStarters(): void
    {
        foreach (config('game.starters.creature_slugs') as $index => $slug) {
            Creature::factory()->create([
                'slug' => $slug,
                'name' => ucfirst($slug),
                'external_id' => $index + 1,
                'species_external_id' => $index + 1,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'handle' => 'AshKetchum',
            'display_name' => 'Ash',
            'email' => 'Ash@Example.com',
            'password' => 'Passw0rd!x',
            'password_confirmation' => 'Passw0rd!x',
        ], $overrides);
    }
}
