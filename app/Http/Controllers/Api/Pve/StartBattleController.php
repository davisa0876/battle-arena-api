<?php

namespace App\Http\Controllers\Api\Pve;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\PveBattlePresenter;
use App\Application\Battles\StartPveBattleService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pve\StartBattleRequest;
use Illuminate\Http\JsonResponse;

final class StartBattleController extends Controller
{
    public function __invoke(
        StartBattleRequest $request,
        StartPveBattleService $start,
        PveBattlePresenter $presenter,
    ): JsonResponse {
        try {
            $result = $start->start(
                $request->user(),
                (int) $request->validated('owned_creature_id'),
                $request->validated('difficulty'),
            );
        } catch (PveBattleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $presenter->present($result['battle'])], 201);
    }
}
