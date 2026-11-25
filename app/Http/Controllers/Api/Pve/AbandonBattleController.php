<?php

namespace App\Http\Controllers\Api\Pve;

use App\Application\Battles\AbandonPveBattleService;
use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\PveBattlePresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AbandonBattleController extends Controller
{
    public function __invoke(
        Request $request,
        string $battle,
        AbandonPveBattleService $abandon,
        PveBattlePresenter $presenter,
    ): JsonResponse {
        try {
            $result = $abandon->abandon($request->user(), $battle);
        } catch (PveBattleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
            ], $e->status);
        }

        return response()->json([
            'data' => [
                'battle' => $presenter->present($result['battle'], $result['reward']),
                'reward' => $result['reward'],
            ],
        ]);
    }
}
