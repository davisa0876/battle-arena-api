<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'battle-arena-api',
            'version' => '0.1.0',
        ]);
    }
}
