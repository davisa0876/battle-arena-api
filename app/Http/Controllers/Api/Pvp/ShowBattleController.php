<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpCombatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowBattleController extends Controller
{
    public function __invoke(Request $request, string $battle, PvpCombatService $combat): JsonResponse
    {
        try {
            $result = $combat->getBattle($request->user(), $battle);
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
