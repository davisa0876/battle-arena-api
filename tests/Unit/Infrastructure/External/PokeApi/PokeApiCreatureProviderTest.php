<?php

namespace Tests\Unit\Infrastructure\External\PokeApi;

use App\Application\Creatures\Contracts\CreatureCatalogProvider;
use App\Application\Creatures\DTOs\ExternalCreatureData;
use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Infrastructure\External\PokeApi\PokeApiClient;
use App\Infrastructure\External\PokeApi\PokeApiCreatureMapper;
use App\Infrastructure\External\PokeApi\PokeApiCreatureProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PokeApiClient is final (not mockable), so the real client is exercised against
 * Http::fake() together with the real mapper.
 */
class PokeApiCreatureProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    private function provider(): PokeApiCreatureProvider
    {
        return new PokeApiCreatureProvider(
            new PokeApiClient(
                baseUrl: 'http://pokeapi.test',
                timeoutSeconds: 1.0,
                retryTimes: 1,
                retrySleepMs: 0,
                requestDelayMs: 0,
                cacheTtlSeconds: 0,
            ),
            new PokeApiCreatureMapper,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function pokemonPayload(int $id, string $name): array
    {
        return [
            'id' => $id,
            'name' => $name,
            'height' => 10,
            'weight' => 100,
            'stats' => [
                ['base_stat' => 40, 'stat' => ['name' => 'hp']],
                ['base_stat' => 41, 'stat' => ['name' => 'attack']],
                ['base_stat' => 42, 'stat' => ['name' => 'defense']],
                ['base_stat' => 43, 'stat' => ['name' => 'special-attack']],
                ['base_stat' => 44, 'stat' => ['name' => 'special-defense']],
                ['base_stat' => 45, 'stat' => ['name' => 'speed']],
            ],
            'types' => [['slot' => 1, 'type' => ['name' => 'normal']]],
            'sprites' => ['front_default' => null],
        ];
    }

    #[Test]
    public function test_provider_name_is_pokeapi(): void
    {
        $this->assertSame('pokeapi', $this->provider()->providerName());
    }

    #[Test]
    public function test_implements_catalog_provider_contract(): void
    {
        $this->assertInstanceOf(CreatureCatalogProvider::class, $this->provider());
    }

    #[Test]
    public function test_fetches_and_maps_every_requested_id_in_order(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/1' => Http::response($this->pokemonPayload(1, 'bulbasaur'), 200),
            'pokeapi.test/pokemon/4' => Http::response($this->pokemonPayload(4, 'charmander'), 200),
        ]);

        $results = $this->provider()->fetchByExternalIds([4, 1]);

        $this->assertCount(2, $results);
        $this->assertContainsOnlyInstancesOf(ExternalCreatureData::class, $results);
        $this->assertSame([4, 1], array_map(fn (ExternalCreatureData $d) => $d->externalId, $results));
        $this->assertSame('Charmander', $results[0]->name);
        $this->assertSame('pokeapi', $results[0]->provider);
        $this->assertSame(40, $results[1]->baseHp);
    }

    #[Test]
    public function test_empty_id_list_returns_empty_array_without_requests(): void
    {
        Http::fake();

        $this->assertSame([], $this->provider()->fetchByExternalIds([]));
        Http::assertNothingSent();
    }

    #[Test]
    public function test_partial_failure_returns_only_successful_creatures(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/1' => Http::response($this->pokemonPayload(1, 'bulbasaur'), 200),
            'pokeapi.test/pokemon/2' => Http::response(['detail' => 'Not found.'], 404),
            'pokeapi.test/pokemon/3' => Http::response($this->pokemonPayload(3, 'venusaur'), 200),
        ]);

        $results = $this->provider()->fetchByExternalIds([1, 2, 3]);

        $this->assertSame([1, 3], array_map(fn (ExternalCreatureData $d) => $d->externalId, $results));
    }

    #[Test]
    public function test_partial_failure_from_server_error_still_returns_survivors(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/1' => Http::response('boom', 500),
            'pokeapi.test/pokemon/2' => Http::response($this->pokemonPayload(2, 'ivysaur'), 200),
        ]);

        $results = $this->provider()->fetchByExternalIds([1, 2]);

        $this->assertCount(1, $results);
        $this->assertSame('Ivysaur', $results[0]->name);
    }

    #[Test]
    public function test_all_failures_throw_provider_exception_listing_every_error(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/1' => Http::response('nope', 404),
            'pokeapi.test/pokemon/2' => Http::response('boom', 500),
        ]);

        try {
            $this->provider()->fetchByExternalIds([1, 2]);
            $this->fail('Expected CreatureProviderException.');
        } catch (CreatureProviderException $exception) {
            $this->assertStringContainsString('failed for all requested creatures', $exception->getMessage());
            $this->assertStringContainsString('pokemon/1', $exception->getMessage());
            $this->assertStringContainsString('pokemon/2', $exception->getMessage());
            $this->assertStringContainsString(' | ', $exception->getMessage());
        }
    }

    #[Test]
    public function test_single_failing_id_throws_provider_exception(): void
    {
        Http::fake(['pokeapi.test/pokemon/1' => Http::response('nope', 404)]);

        $this->expectException(CreatureProviderException::class);

        $this->provider()->fetchByExternalIds([1]);
    }

    #[Test]
    public function test_incomplete_but_fetched_payload_is_returned_for_the_importer_to_judge(): void
    {
        $payload = $this->pokemonPayload(5, 'broken');
        $payload['types'] = [];

        Http::fake(['pokeapi.test/pokemon/5' => Http::response($payload, 200)]);

        $results = $this->provider()->fetchByExternalIds([5]);

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isComplete());
    }
}
