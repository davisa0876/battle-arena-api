<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpCombatService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pvp\DefendPvpBattleRequest;
use Illuminate\Http\JsonResponse;

final class DefendBattleController extends Controller
{
    public function __invoke(DefendPvpBattleRequest $request, string $battle, PvpCombatService $combat): JsonResponse
    {
        $key = $request->idempotencyKey();
        if ($key === '') {
            return response()->json(['message' => 'Idempotency key is required.', 'code' => 'IDEMPOTENCY_REQUIRED'], 422);
        }

        try {
            $result = $combat->defend(
                $request->user(),
                $battle,
                (string) $request->validated('defense'),
                (int) $request->validated('expected_version'),
                $key,
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
