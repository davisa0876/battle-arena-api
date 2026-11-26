<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\PvpOnlineOpponentsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListOnlineOpponentsController extends Controller
{
    public function __invoke(Request $request, PvpOnlineOpponentsService $opponents): JsonResponse
    {
        return response()->json([
            'data' => $opponents->listFor($request->user()),
        ]);
    }
}
