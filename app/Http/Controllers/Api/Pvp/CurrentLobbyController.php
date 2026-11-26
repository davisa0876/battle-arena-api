<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\PvpLobbyService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CurrentLobbyController extends Controller
{
    public function __invoke(Request $request, PvpLobbyService $lobbies): JsonResponse
    {
        return response()->json([
            'data' => $lobbies->currentLobby($request->user()),
        ]);
    }
}
