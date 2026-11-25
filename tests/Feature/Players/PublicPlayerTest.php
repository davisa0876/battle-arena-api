<?php

namespace Tests\Feature\Players;

use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class PublicPlayerTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    public function test_public_profile_is_visible_without_authentication_and_hides_private_data(): void
    {
        $user = $this->makePlayer(['handle' => 'misty', 'display_name' => 'Misty', 'bio' => 'Hi'], ['email' => 'misty@example.com']);

        $response = $this->getJson('/api/players/misty')
            ->assertOk()
            ->assertJsonPath('data.handle', 'misty')
            ->assertJsonPath('data.display_name', 'Misty')
            ->assertJsonPath('data.bio', 'Hi')
            ->assertJsonPath('data.trainer.level', 1)
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.id')
            ->assertJsonMissingPath('data.creatures');

        $this->assertStringNotContainsString('misty@example.com', $response->getContent());
        $this->assertStringNotContainsString((string) $user->password, $response->getContent());
    }

    public function test_handle_lookup_is_case_insensitive(): void
    {
        $this->makePlayer(['handle' => 'misty']);

        $this->getJson('/api/players/MiStY')->assertOk()->assertJsonPath('data.handle', 'misty');
    }

    public function test_unknown_handle_returns_a_localized_404(): void
    {
        $this->withHeaders(['Accept-Language' => 'pt-BR'])
            ->getJson('/api/players/nobody')
            ->assertNotFound()
            ->assertJsonPath('message', 'Treinador não encontrado.');
    }

    public function test_private_collection_is_not_exposed(): void
    {
        $user = $this->makePlayer(['handle' => 'misty', 'collection_is_public' => false]);
        OwnedCreature::factory()->active()->create(['user_id' => $user->id]);

        $this->getJson('/api/players/misty')
            ->assertOk()
            ->assertJsonPath('data.collection_is_public', false)
            ->assertJsonMissingPath('data.creatures');
    }

    public function test_public_collection_lists_creatures(): void
    {
        $user = $this->makePlayer(['handle' => 'misty', 'collection_is_public' => true]);
        OwnedCreature::factory()->active()->create(['user_id' => $user->id]);

        $this->getJson('/api/players/misty')
            ->assertOk()
            ->assertJsonPath('data.collection_is_public', true)
            ->assertJsonCount(1, 'data.creatures')
            ->assertJsonPath('data.creatures.0.is_active', true)
            ->assertJsonStructure(['data' => ['creatures' => [['id', 'level', 'creature' => ['slug', 'base_stats']]]]]);
    }
}
