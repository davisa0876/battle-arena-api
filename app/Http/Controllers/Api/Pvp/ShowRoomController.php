<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpLobbyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowRoomController extends Controller
{
    public function __invoke(Request $request, string $room, PvpLobbyService $lobbies): JsonResponse
    {
        try {
            $data = $lobbies->getRoom($request->user(), $room);
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json(['data' => $data]);
    }
}
