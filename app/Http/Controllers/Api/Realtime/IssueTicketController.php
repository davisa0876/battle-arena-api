<?php

namespace App\Http\Controllers\Api\Realtime;

use App\Application\Realtime\IssueRealtimeTicketService;
use App\Http\Controllers\Controller;
use App\Http\Resources\RealtimeTicketResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class IssueTicketController extends Controller
{
    public function __construct(
        private readonly IssueRealtimeTicketService $tickets,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 401);

        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $accessToken = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
        $tokenId = ($accessToken !== null && isset($accessToken->id))
            ? (string) $accessToken->id
            : null;

        try {
            $result = $this->tickets->issue(
                $user,
                is_string($sessionId) ? $sessionId : null,
                $tokenId,
            );
        } catch (RuntimeException) {
            return response()->json([
                'message' => __('api.realtime.ticket_unavailable'),
            ], 503)->header('Cache-Control', 'no-store');
        }

        return (new RealtimeTicketResource($result))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store');
    }
}
