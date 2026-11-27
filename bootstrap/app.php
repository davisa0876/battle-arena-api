<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        // Telescope is a dev-only package; production (--no-dev) builds do not have the command.
        if (class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $schedule->command('telescope:prune')->daily();
        }
        // Safe no-op while PVP_TURN_TIMEOUT_ENABLED=false; never mass-forfeit on backlog alone.
        $schedule->command('pvp:adjudicate-action-deadlines')->everyMinute()->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // This is a JSON API: there is no web login page to redirect guests to.
        $middleware->redirectGuestsTo(static fn (): ?string => null);

        $middleware->api(prepend: [
            HandleCors::class,
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            static fn (Request $request, Throwable $e): bool => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(static function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => __('api.unauthenticated')], 401);
            }

            return null;
        });
    })->create();
