<?php

use App\Http\Controllers\Api\Auth\EmailVerificationController;
use App\Http\Controllers\Api\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\Auth\LoginController;
use App\Http\Controllers\Api\Auth\LogoutController;
use App\Http\Controllers\Api\Auth\RegisterController;
use App\Http\Controllers\Api\Auth\ResetPasswordController;
use App\Http\Controllers\Api\Collection\AcquireCreatureController;
use App\Http\Controllers\Api\Collection\OwnedCreatureController;
use App\Http\Controllers\Api\Collection\SelectOwnedCreatureController;
use App\Http\Controllers\Api\Collection\StarterController;
use App\Http\Controllers\Api\CreatureController;
use App\Http\Controllers\Api\CreatureTypeController;
use App\Http\Controllers\Api\GameRulesController;
use App\Http\Controllers\Api\Profile\AvatarController;
use App\Http\Controllers\Api\Profile\AvatarStreamController;
use App\Http\Controllers\Api\Profile\MeController;
use App\Http\Controllers\Api\Profile\PublicPlayerController;
use App\Http\Controllers\Api\Profile\UpdatePasswordController;
use App\Http\Controllers\Api\Profile\UpdateProfileController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/ready', ReadyController::class);

// Catalog (public, read-only)
Route::get('/creatures', [CreatureController::class, 'index']);
Route::get('/creatures/{slug}', [CreatureController::class, 'show']);
Route::get('/creature-types', [CreatureTypeController::class, 'index']);

// Game rules (public)
Route::get('/game/rules', GameRulesController::class);

// Public player data
Route::get('/players/{handle}', [PublicPlayerController::class, 'show']);
Route::get('/avatars/{userId}', AvatarStreamController::class)->whereNumber('userId')->name('avatars.show');

// Authentication (public)
Route::post('/register', RegisterController::class)->middleware('throttle:auth-register');
Route::post('/login', LoginController::class)->middleware('throttle:auth-login');
Route::post('/forgot-password', ForgotPasswordController::class)->middleware('throttle:auth-recovery');
Route::post('/reset-password', ResetPasswordController::class)->middleware('throttle:auth-recovery');
Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
    ->whereNumber('id')
    ->middleware(['signed', 'throttle:auth-verification'])
    ->name('verification.verify');

// Authenticated (Sanctum: session cookie for web, bearer token for mobile)
Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', LogoutController::class);

    Route::get('/me', [MeController::class, 'show']);
    Route::patch('/me/profile', UpdateProfileController::class);
    Route::patch('/me/password', UpdatePasswordController::class);
    Route::post('/me/avatar', [AvatarController::class, 'store']);
    Route::delete('/me/avatar', [AvatarController::class, 'destroy']);

    Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
        ->middleware('throttle:auth-verification');

    Route::get('/me/creatures', [OwnedCreatureController::class, 'index']);
    Route::post('/me/starter', StarterController::class);
    Route::post('/me/creatures/acquire', AcquireCreatureController::class);
    Route::get('/me/creatures/{id}', [OwnedCreatureController::class, 'show'])->whereNumber('id');
    Route::patch('/me/creatures/{id}/select', SelectOwnedCreatureController::class)->whereNumber('id');
});
