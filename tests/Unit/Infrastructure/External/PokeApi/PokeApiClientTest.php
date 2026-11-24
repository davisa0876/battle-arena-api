<?php

namespace Tests\Unit\Infrastructure\External\PokeApi;

use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Infrastructure\External\PokeApi\PokeApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PokeApiClientTest extends TestCase
{
    private const BASE_URL = 'http://pokeapi.test';

    protected function setUp(): void
    {
        parent::setUp();

        // CACHE_STORE=array in phpunit.xml: an isolated in-memory store per test.
        Cache::flush();
    }

    private function client(int $retryTimes = 1, int $cacheTtl = 0, string $baseUrl = self::BASE_URL): PokeApiClient
    {
        return new PokeApiClient(
            baseUrl: $baseUrl,
            timeoutSeconds: 1.0,
            retryTimes: $retryTimes,
            retrySleepMs: 0,
            requestDelayMs: 0,
            cacheTtlSeconds: $cacheTtl,
        );
    }

    #[Test]
    public function test_returns_decoded_json_payload(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/25' => Http::response(['id' => 25, 'name' => 'pikachu'], 200),
        ]);

        $payload = $this->client()->getPokemon(25);

        $this->assertSame(['id' => 25, 'name' => 'pikachu'], $payload);
    }

    #[Test]
    public function test_requests_the_pokemon_endpoint_with_json_accept_header(): void
    {
        Http::fake(['pokeapi.test/*' => Http::response(['id' => 7], 200)]);

        $this->client()->getPokemon(7);

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'GET'
                && $request->url() === 'http://pokeapi.test/pokemon/7'
                && str_contains($request->header('Accept')[0] ?? '', 'application/json');
        });
        Http::assertSentCount(1);
    }

    #[Test]
    public function test_trailing_slash_on_base_url_is_normalised(): void
    {
        Http::fake(['pokeapi.test/*' => Http::response(['id' => 7], 200)]);

        $this->client(baseUrl: self::BASE_URL.'/')->getPokemon(7);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://pokeapi.test/pokemon/7');
    }

    #[Test]
    public function test_caches_payload_when_ttl_is_positive_and_skips_network_on_second_call(): void
    {
        Http::fake(['pokeapi.test/pokemon/25' => Http::response(['id' => 25, 'name' => 'pikachu'], 200)]);

        $client = $this->client(cacheTtl: 60);

        $first = $client->getPokemon(25);
        $second = $client->getPokemon(25);

        $this->assertSame($first, $second);
        Http::assertSentCount(1);
        $this->assertSame(['id' => 25, 'name' => 'pikachu'], Cache::get('pokeapi:pokemon:25'));
    }

    #[Test]
    public function test_cache_is_keyed_per_external_id(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/1' => Http::response(['id' => 1], 200),
            'pokeapi.test/pokemon/2' => Http::response(['id' => 2], 200),
        ]);

        $client = $this->client(cacheTtl: 60);

        $this->assertSame(['id' => 1], $client->getPokemon(1));
        $this->assertSame(['id' => 2], $client->getPokemon(2));
        Http::assertSentCount(2);
    }

    #[Test]
    public function test_returns_pre_populated_cache_entry_without_network(): void
    {
        Http::fake();
        Cache::put('pokeapi:pokemon:9', ['id' => 9, 'name' => 'cached'], 60);

        $payload = $this->client(cacheTtl: 60)->getPokemon(9);

        $this->assertSame(['id' => 9, 'name' => 'cached'], $payload);
        Http::assertNothingSent();
    }

    #[Test]
    public function test_does_not_cache_when_ttl_is_zero(): void
    {
        Http::fake(['pokeapi.test/pokemon/25' => Http::response(['id' => 25], 200)]);

        $client = $this->client(cacheTtl: 0);

        $client->getPokemon(25);
        $client->getPokemon(25);

        Http::assertSentCount(2);
        $this->assertNull(Cache::get('pokeapi:pokemon:25'));
    }

    #[Test]
    public function test_ignores_stale_cache_entries_when_ttl_is_zero(): void
    {
        Cache::put('pokeapi:pokemon:25', ['id' => 25, 'name' => 'stale'], 60);
        Http::fake(['pokeapi.test/pokemon/25' => Http::response(['id' => 25, 'name' => 'fresh'], 200)]);

        $payload = $this->client(cacheTtl: 0)->getPokemon(25);

        $this->assertSame('fresh', $payload['name']);
    }

    #[Test]
    public function test_client_error_throws_provider_exception_without_retry(): void
    {
        Http::fake(['pokeapi.test/pokemon/99999' => Http::response(['detail' => 'Not found.'], 404)]);

        try {
            $this->client(retryTimes: 3)->getPokemon(99999);
            $this->fail('Expected CreatureProviderException.');
        } catch (CreatureProviderException $exception) {
            $this->assertStringContainsString('HTTP 404', $exception->getMessage());
            $this->assertStringContainsString('pokemon/99999', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    #[Test]
    public function test_client_error_is_not_cached(): void
    {
        Http::fake(['pokeapi.test/pokemon/404' => Http::response('nope', 404)]);

        try {
            $this->client(cacheTtl: 60)->getPokemon(404);
        } catch (CreatureProviderException) {
            // expected
        }

        $this->assertNull(Cache::get('pokeapi:pokemon:404'));
    }

    #[Test]
    public function test_server_error_after_retries_throws_provider_exception(): void
    {
        Http::fake(['pokeapi.test/pokemon/1' => Http::response('boom', 500)]);

        try {
            $this->client(retryTimes: 3)->getPokemon(1);
            $this->fail('Expected CreatureProviderException.');
        } catch (CreatureProviderException $exception) {
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }

        Http::assertSentCount(3);
    }

    #[Test]
    public function test_server_error_without_retries_makes_a_single_attempt(): void
    {
        Http::fake(['pokeapi.test/pokemon/1' => Http::response('boom', 503)]);

        $this->expectException(CreatureProviderException::class);

        try {
            $this->client(retryTimes: 0)->getPokemon(1);
        } finally {
            Http::assertSentCount(1);
        }
    }

    #[Test]
    public function test_transient_server_error_recovers_on_retry(): void
    {
        Http::fake([
            'pokeapi.test/pokemon/1' => Http::sequence()
                ->push('boom', 500)
                ->push(['id' => 1, 'name' => 'bulbasaur'], 200),
        ]);

        $payload = $this->client(retryTimes: 2)->getPokemon(1);

        $this->assertSame('bulbasaur', $payload['name']);
        Http::assertSentCount(2);
    }

    #[Test]
    public function test_connection_failure_is_wrapped_in_provider_exception(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Simulated timeout');
        });

        try {
            $this->client(retryTimes: 1)->getPokemon(1);
            $this->fail('Expected CreatureProviderException.');
        } catch (CreatureProviderException $exception) {
            $this->assertStringContainsString('connection failed', $exception->getMessage());
            $this->assertStringContainsString('Simulated timeout', $exception->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }
    }

    #[Test]
    public function test_non_array_json_payload_throws_provider_exception(): void
    {
        Http::fake(['pokeapi.test/pokemon/1' => Http::response('"just a string"', 200, ['Content-Type' => 'application/json'])]);

        try {
            $this->client()->getPokemon(1);
            $this->fail('Expected CreatureProviderException.');
        } catch (CreatureProviderException $exception) {
            $this->assertStringContainsString('non-JSON payload', $exception->getMessage());
        }
    }

    #[Test]
    public function test_invalid_json_body_throws_provider_exception(): void
    {
        Http::fake(['pokeapi.test/pokemon/1' => Http::response('<html>not json</html>', 200)]);

        $this->expectException(CreatureProviderException::class);

        $this->client()->getPokemon(1);
    }

    #[Test]
    public function test_non_array_payload_is_not_cached(): void
    {
        Http::fake(['pokeapi.test/pokemon/1' => Http::response('<html>not json</html>', 200)]);

        try {
            $this->client(cacheTtl: 60)->getPokemon(1);
        } catch (CreatureProviderException) {
            // expected
        }

        $this->assertNull(Cache::get('pokeapi:pokemon:1'));
    }
}
