<?php

namespace App\Http\Controllers\Api\Collection;

use App\Application\OwnedCreatures\OwnedCreatureService;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnedCreatureResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SelectOwnedCreatureController extends Controller
{
    public function __construct(
        private readonly OwnedCreatureService $ownedCreatures,
        private readonly Gate $gate,
    ) {}

    public function __invoke(Request $request, int $id): OwnedCreatureResource|JsonResponse
    {
        $owned = $this->ownedCreatures->findById($id);

        if ($owned === null) {
            return response()->json(['message' => __('api.owned_creature_not_found')], 404);
        }

        $this->gate->authorize('select', $owned);

        return new OwnedCreatureResource($this->ownedCreatures->selectActive($request->user(), $owned));
    }
}
