<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Application\Pvp\PvpLobbyService;
use App\Http\Controllers\Api\Pvp\Concerns\ResolvesPvpIdempotencyKey;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AcceptInvitationController extends Controller
{
    use ResolvesPvpIdempotencyKey;

    public function __invoke(Request $request, string $invitation, PvpLobbyService $lobbies): JsonResponse
    {
        try {
            $result = $lobbies->acceptInvitation(
                $request->user(),
                $invitation,
                $this->idempotencyKey($request),
            );
        } catch (PvpLobbyException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json($result['body'], $result['status']);
    }
}
