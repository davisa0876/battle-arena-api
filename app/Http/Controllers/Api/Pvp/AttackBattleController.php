<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpCombatService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pvp\AttackPvpBattleRequest;
use Illuminate\Http\JsonResponse;

final class AttackBattleController extends Controller
{
    public function __invoke(AttackPvpBattleRequest $request, string $battle, PvpCombatService $combat): JsonResponse
    {
        $key = $request->idempotencyKey();
        if ($key === '') {
            return response()->json(['message' => 'Idempotency key is required.', 'code' => 'IDEMPOTENCY_REQUIRED'], 422);
        }

        try {
            $result = $combat->attack(
                $request->user(),
                $battle,
                $request->filled('move_id') ? (int) $request->input('move_id') : null,
                (bool) $request->boolean('basic_strike'),
                (bool) $request->boolean('recover'),
                (int) $request->validated('expected_version'),
                $key,
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
