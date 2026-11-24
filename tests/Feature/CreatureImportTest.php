<?php

namespace Tests\Feature;

use App\Application\Creatures\Services\CreatureImportService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CreatureImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_creature_import_persists_stats_and_types(): void
    {
        Http::fake([
            'https://pokeapi.co/api/v2/pokemon/1' => Http::response($this->fixture('pokemon_1.json'), 200),
            'https://pokeapi.co/api/v2/pokemon/4' => Http::response($this->fixture('pokemon_4.json'), 200),
        ]);

        $summary = $this->app->make(CreatureImportService::class)->import([1, 4]);

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(0, $summary['failed']);
        $this->assertDatabaseCount('creatures', 2);

        $bulbasaur = Creature::query()->where('slug', 'bulbasaur')->with('types')->firstOrFail();
        $this->assertSame('pokeapi', $bulbasaur->provider);
        $this->assertSame(1, $bulbasaur->external_id);
        $this->assertSame(45, $bulbasaur->base_hp);
        $this->assertSame(70, $bulbasaur->height_cm);
        $this->assertSame(6900, $bulbasaur->weight_g);
        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/1.png',
            $bulbasaur->image_ref,
        );
        $this->assertSame(['grass', 'poison'], $bulbasaur->types->pluck('slug')->all());
        $this->assertSame([1, 2], $bulbasaur->types->pluck('pivot.slot')->all());
    }

    public function test_import_backfills_image_without_clearing_existing_on_null(): void
    {
        $existing = Creature::factory()->create([
            'provider' => 'pokeapi',
            'external_id' => 1,
            'name' => 'Bulbasaur',
            'slug' => 'bulbasaur',
            'image_ref' => 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/1.png',
        ]);

        $payload = $this->fixture('pokemon_1.json');
        $payload['sprites'] = ['front_default' => null, 'other' => []];

        Http::fake([
            'https://pokeapi.co/api/v2/pokemon/1' => Http::response($payload, 200),
        ]);

        $summary = $this->app->make(CreatureImportService::class)->import([1]);

        $this->assertSame(1, $summary['updated']);
        $existing->refresh();
        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/1.png',
            $existing->image_ref,
        );
    }

    public function test_import_is_idempotent(): void
    {
        Http::fake([
            'https://pokeapi.co/api/v2/pokemon/1' => Http::response($this->fixture('pokemon_1.json'), 200),
        ]);

        $service = $this->app->make(CreatureImportService::class);
        $first = $service->import([1]);
        $second = $service->import([1]);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $second['updated']);
        $this->assertDatabaseCount('creatures', 1);
        $this->assertSame(1, CreatureType::query()->where('slug', 'grass')->count());
    }

    public function test_provider_timeout_does_not_corrupt_catalog(): void
    {
        Cache::flush();

        Creature::factory()->create([
            'provider' => 'pokeapi',
            'external_id' => 1,
            'name' => 'Existing',
            'slug' => 'existing',
        ]);

        Http::fake([
            'https://pokeapi.co/api/v2/pokemon/1' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Simulated timeout');
            },
        ]);

        $this->expectException(\App\Application\Creatures\Exceptions\CreatureProviderException::class);

        try {
            $this->app->make(CreatureImportService::class)->import([1]);
        } finally {
            $this->assertDatabaseCount('creatures', 1);
            $this->assertDatabaseHas('creatures', ['slug' => 'existing', 'name' => 'Existing']);
        }
    }

    public function test_incomplete_provider_payload_is_skipped_without_replacing_valid_data(): void
    {
        $existing = Creature::factory()->create([
            'provider' => 'pokeapi',
            'external_id' => 9999,
            'name' => 'Keeper',
            'slug' => 'keeper',
            'base_hp' => 100,
        ]);

        Http::fake([
            'https://pokeapi.co/api/v2/pokemon/9999' => Http::response($this->fixture('pokemon_incomplete.json'), 200),
        ]);

        $summary = $this->app->make(CreatureImportService::class)->import([9999]);

        $this->assertSame(1, $summary['skipped']);
        $existing->refresh();
        $this->assertSame('Keeper', $existing->name);
        $this->assertSame(100, $existing->base_hp);
    }

    public function test_artisan_import_command_uses_mocked_http(): void
    {
        Http::fake([
            'https://pokeapi.co/api/v2/pokemon/4' => Http::response($this->fixture('pokemon_4.json'), 200),
        ]);

        $this->artisan('creatures:import', ['--ids' => '4'])
            ->assertSuccessful();

        $this->assertDatabaseHas('creatures', [
            'slug' => 'charmander',
            'provider' => 'pokeapi',
            'external_id' => 4,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $filename): array
    {
        $path = base_path('tests/Fixtures/pokeapi/'.$filename);
        $decoded = json_decode((string) file_get_contents($path), true);

        $this->assertIsArray($decoded);

        return $decoded;
    }
}
