<?php

namespace App\Http\Controllers\Api\Pve;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\GetPveBattleService;
use App\Application\Battles\PveBattlePresenter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BattleStateController extends Controller
{
    public function __invoke(
        Request $request,
        string $battle,
        GetPveBattleService $get,
        PveBattlePresenter $presenter,
    ): JsonResponse {
        try {
            $row = $get->ownedBattle($request->user(), $battle);
        } catch (PveBattleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $presenter->present($row)]);
    }
}
