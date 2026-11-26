<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpCombatService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pvp\ReadyPvpRoomRequest;
use Illuminate\Http\JsonResponse;

final class ReadyRoomController extends Controller
{
    public function __invoke(ReadyPvpRoomRequest $request, string $room, PvpCombatService $combat): JsonResponse
    {
        try {
            $result = $combat->ready(
                $request->user(),
                $room,
                (int) $request->validated('expected_room_version'),
                $request->idempotencyKey(),
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
