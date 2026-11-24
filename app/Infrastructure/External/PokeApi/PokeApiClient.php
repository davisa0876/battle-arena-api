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
        $cacheKey = "pokeapi:pokemon:{$externalId}";

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
                ->get("/pokemon/{$externalId}");
        } catch (ConnectionException $exception) {
            throw new CreatureProviderException(
                "PokéAPI connection failed for pokemon/{$externalId}: {$exception->getMessage()}",
                previous: $exception,
            );
        }

        if ($response->clientError()) {
            throw new CreatureProviderException(
                "PokéAPI returned HTTP {$response->status()} for pokemon/{$externalId}."
            );
        }

        if ($response->failed()) {
            throw new CreatureProviderException(
                "PokéAPI request failed with HTTP {$response->status()} for pokemon/{$externalId}."
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new CreatureProviderException(
                "PokéAPI returned a non-JSON payload for pokemon/{$externalId}."
            );
        }

        if ($this->cacheTtlSeconds > 0) {
            Cache::put($cacheKey, $payload, $this->cacheTtlSeconds);
        }

        return $payload;
    }
}
