<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class ReadyController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'postgres' => $this->checkPostgres(),
            'redis' => $this->checkRedis(),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ready' : 'unavailable',
            'service' => 'battle-arena-api',
            'checks' => [
                'postgres' => $checks['postgres'] ? 'ok' : 'fail',
                'redis' => $checks['redis'] ? 'ok' : 'fail',
            ],
        ], $ready ? 200 : 503);
    }

    private function checkPostgres(): bool
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::connection()->ping();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
