<?php

use App\Http\Controllers\Api\CreatureController;
use App\Http\Controllers\Api\CreatureTypeController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/ready', ReadyController::class);

Route::get('/creatures', [CreatureController::class, 'index']);
Route::get('/creatures/{slug}', [CreatureController::class, 'show']);
Route::get('/creature-types', [CreatureTypeController::class, 'index']);
