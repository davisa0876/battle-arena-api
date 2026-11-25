<?php

namespace App\Http\Controllers\Api\Collection;

use App\Application\OwnedCreatures\OwnedCreatureService;
use App\Http\Controllers\Controller;
use App\Http\Resources\OwnedCreatureResource;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OwnedCreatureController extends Controller
{
    public function __construct(
        private readonly OwnedCreatureService $ownedCreatures,
        private readonly Gate $gate,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OwnedCreatureResource::collection($this->ownedCreatures->listFor($request->user()));
    }

    public function show(int $id): OwnedCreatureResource|JsonResponse
    {
        $owned = $this->ownedCreatures->findById($id);

        if ($owned === null) {
            return response()->json(['message' => __('api.owned_creature_not_found')], 404);
        }

        $this->gate->authorize('view', $owned);

        return new OwnedCreatureResource($owned);
    }
}
