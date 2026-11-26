<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpCombatService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pvp\SelectPvpCreatureRequest;
use Illuminate\Http\JsonResponse;

final class SelectCreatureController extends Controller
{
    public function __invoke(SelectPvpCreatureRequest $request, string $room, PvpCombatService $combat): JsonResponse
    {
        try {
            $result = $combat->selectCreature(
                $request->user(),
                $room,
                (int) $request->validated('owned_creature_id'),
                (int) $request->validated('expected_room_version'),
                $request->idempotencyKey(),
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
