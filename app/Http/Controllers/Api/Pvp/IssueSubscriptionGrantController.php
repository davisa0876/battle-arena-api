<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\IssueRoomSubscriptionGrantService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class IssueSubscriptionGrantController extends Controller
{
    public function __invoke(
        Request $request,
        string $room,
        IssueRoomSubscriptionGrantService $grants,
    ): JsonResponse {
        try {
            $result = $grants->issue($request->user(), $room);
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status)
                ->header('Cache-Control', 'no-store');
        }

        return response()->json(['data' => $result], 201)
            ->header('Cache-Control', 'no-store');
    }
}
