<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReadyController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class);
Route::get('/ready', ReadyController::class);
