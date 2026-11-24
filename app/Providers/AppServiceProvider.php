<?php

namespace App\Providers;

use App\Application\Creatures\Contracts\CreatureCatalogProvider;
use App\Infrastructure\External\PokeApi\PokeApiClient;
use App\Infrastructure\External\PokeApi\PokeApiCreatureMapper;
use App\Infrastructure\External\PokeApi\PokeApiCreatureProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PokeApiClient::class, function () {
            $config = config('creatures.providers.pokeapi');

            return new PokeApiClient(
                baseUrl: (string) $config['base_url'],
                timeoutSeconds: (float) $config['timeout_seconds'],
                retryTimes: (int) $config['retry_times'],
                retrySleepMs: (int) $config['retry_sleep_ms'],
                requestDelayMs: (int) $config['request_delay_ms'],
                cacheTtlSeconds: (int) $config['cache_ttl_seconds'],
            );
        });

        $this->app->singleton(PokeApiCreatureMapper::class);

        $this->app->bind(CreatureCatalogProvider::class, PokeApiCreatureProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
