<?php

namespace App\Providers;

use App\Application\Creatures\Contracts\CreatureCatalogProvider;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use App\Infrastructure\External\PokeApi\PokeApiClient;
use App\Infrastructure\External\PokeApi\PokeApiCreatureMapper;
use App\Infrastructure\External\PokeApi\PokeApiCreatureProvider;
use App\Policies\OwnedCreaturePolicy;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

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

    public function boot(): void
    {
        Gate::policy(OwnedCreature::class, OwnedCreaturePolicy::class);

        $this->configureRateLimiting();
        $this->configureAuthNotifications();
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('auth-login', fn (Request $request): Limit => Limit::perMinute((int) config('game.rate_limits.login'))
            ->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));

        RateLimiter::for('auth-register', fn (Request $request): Limit => Limit::perMinute((int) config('game.rate_limits.register'))
            ->by($request->ip()));

        RateLimiter::for('auth-recovery', fn (Request $request): Limit => Limit::perMinute((int) config('game.rate_limits.password_recovery'))
            ->by($request->ip()));

        RateLimiter::for('auth-verification', fn (Request $request): Limit => Limit::perMinute((int) config('game.rate_limits.email_verification'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('pve-actions', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('realtime-tickets', fn (Request $request): Limit => Limit::perMinute((int) config('realtime.rate_limit_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('pvp-mutations', fn (Request $request): Limit => Limit::perMinute((int) config('pvp.rate_limits.mutations_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for('pvp-grants', fn (Request $request): Limit => Limit::perMinute((int) config('pvp.rate_limits.grants_per_minute'))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    /**
     * Verification mails keep Laravel's signed API URL (works for web and mobile; the endpoint
     * redirects browsers to the frontend). Reset links point at the frontend reset screen.
     */
    private function configureAuthNotifications(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, string $token): string {
            return config('game.frontend.reset_password_url').'?'.http_build_query([
                'token' => $token,
                'email' => $notifiable->getEmailForPasswordReset(),
            ]);
        });
    }
}
