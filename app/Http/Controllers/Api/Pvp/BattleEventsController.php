<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpCombatService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BattleEventsController extends Controller
{
    public function __invoke(Request $request, string $battle, PvpCombatService $combat): JsonResponse
    {
        try {
            $result = $combat->listEvents(
                $request->user(),
                $battle,
                max(0, (int) $request->query('after_sequence', 0)),
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
