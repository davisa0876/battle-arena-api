<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        // Record only in local — never keep HTTP/query noise outside APP_ENV=local.
        Telescope::filter(fn (IncomingEntry $entry): bool => $this->app->environment('local'));
    }

    /**
     * UI only on this machine: APP_ENV=local AND Host is loopback.
     * LAN/mobile access (e.g. http://192.168.x.x:8000/telescope) is denied even when the API is bound to 0.0.0.0.
     */
    protected function authorization(): void
    {
        $this->gate();

        Telescope::auth(function (Request $request): bool {
            if (! $this->app->environment('local')) {
                return Gate::check('viewTelescope', [$request->user()]);
            }

            return $this->isLoopbackHost($request);
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'set-cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * Non-local access stays deny-by-default (empty allowlist).
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (?User $user): bool {
            return false;
        });
    }

    private function isLoopbackHost(Request $request): bool
    {
        $host = strtolower($request->getHost());

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }
}
