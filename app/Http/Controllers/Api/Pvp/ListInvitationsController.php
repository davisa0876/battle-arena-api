<?php

namespace App\Http\Controllers\Api\Pvp;

use App\Application\Pvp\PvpLobbyPresenter;
use App\Application\Pvp\PvpLobbyService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pvp\ListPvpInvitationsRequest;
use Illuminate\Http\JsonResponse;

final class ListInvitationsController extends Controller
{
    public function __invoke(
        ListPvpInvitationsRequest $request,
        PvpLobbyService $lobbies,
        PvpLobbyPresenter $presenter,
    ): JsonResponse {
        $box = (string) ($request->validated('box') ?? 'received');
        $perPage = (int) ($request->validated('per_page') ?? 20);
        $page = $lobbies->listInvitations($request->user(), $box, $perPage);

        return response()->json([
            'data' => collect($page->items())->map(
                fn ($invitation) => $presenter->presentInvitation($invitation, $request->user()),
            )->values(),
            'meta' => [
                'box' => $box,
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }
}
