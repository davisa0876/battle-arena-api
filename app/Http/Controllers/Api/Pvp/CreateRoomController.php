<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpLobbyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pvp\CreatePvpRoomRequest;
use Illuminate\Http\JsonResponse;

final class CreateRoomController extends Controller
{
    public function __invoke(CreatePvpRoomRequest $request, PvpLobbyService $lobbies): JsonResponse
    {
        try {
            $result = $lobbies->createRoom(
                $request->user(),
                (string) $request->validated('invitee_handle'),
                $request->idempotencyKey(),
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
