<?php

namespace App\Infrastructure\External\PokeApi;

use App\Application\Creatures\Exceptions\CreatureProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class PokeApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly float $timeoutSeconds,
        private readonly int $retryTimes,
        private readonly int $retrySleepMs,
        private readonly int $requestDelayMs,
        private readonly int $cacheTtlSeconds,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function getPokemon(int $externalId): array
    {
        return $this->getJson("/pokemon/{$externalId}", "pokeapi:pokemon:{$externalId}");
    }

    /**
     * @return array<string, mixed>
     */
    public function getPokemonSpecies(int $speciesExternalId): array
    {
        return $this->getJson("/pokemon-species/{$speciesExternalId}", "pokeapi:species:{$speciesExternalId}");
    }

    /**
     * @param  int|string  $idOrSlug  PokéAPI move id or slug
     * @return array<string, mixed>
     */
    public function getMove(int|string $idOrSlug): array
    {
        $key = is_int($idOrSlug) ? (string) $idOrSlug : mb_strtolower(trim($idOrSlug));

        return $this->getJson("/move/{$key}", "pokeapi:move:{$key}");
    }

    /**
     * National dex species IDs (stable catalog universe for Battle Arena).
     *
     * @return list<int>
     */
    public function listSpeciesExternalIds(): array
    {
        $payload = $this->getJson('/pokemon-species?limit=1&offset=0', 'pokeapi:species:count');
        $count = (int) ($payload['count'] ?? 0);
        if ($count < 1) {
            throw new CreatureProviderException('PokéAPI returned an empty pokemon-species catalog.');
        }

        $ids = [];
        $offset = 0;
        $limit = 200;

        while ($offset < $count) {
            $page = $this->getJson(
                "/pokemon-species?limit={$limit}&offset={$offset}",
                "pokeapi:species:page:{$offset}:{$limit}",
            );

            foreach ($page['results'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $url = (string) ($row['url'] ?? '');
                if (preg_match('#/pokemon-species/(\d+)/?#', $url, $matches) !== 1) {
                    continue;
                }

                $ids[] = (int) $matches[1];
            }

            $offset += $limit;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $path, string $cacheKey): array
    {
        if ($this->cacheTtlSeconds > 0) {
            /** @var array<string, mixed>|null $cached */
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                return $cached;
            }
        }

        if ($this->requestDelayMs > 0) {
            usleep($this->requestDelayMs * 1000);
        }

        try {
            $response = Http::baseUrl(rtrim($this->baseUrl, '/'))
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->retry($this->retryTimes, $this->retrySleepMs, function ($exception) {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response?->serverError());
                }, throw: false)
                ->get($path);
        } catch (ConnectionException $exception) {
            throw new CreatureProviderException(
                "PokéAPI connection failed for {$path}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if ($response->clientError()) {
            throw new CreatureProviderException(
                "PokéAPI returned HTTP {$response->status()} for {$path}."
            );
        }

        if ($response->failed()) {
            throw new CreatureProviderException(
                "PokéAPI request failed with HTTP {$response->status()} for {$path}."
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new CreatureProviderException(
                "PokéAPI returned a non-JSON payload for {$path}."
            );
        }

        if ($this->cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $payload, $this->cacheTtlSeconds);
        }

        return $payload;
    }
}
