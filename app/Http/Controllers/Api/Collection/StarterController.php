<?php

namespace App\Http\Controllers\Api\Collection;

use App\Application\OwnedCreatures\StarterSelectionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\SelectStarterRequest;
use App\Http\Resources\OwnedCreatureResource;
use Illuminate\Http\JsonResponse;

class StarterController extends Controller
{
    public function __construct(
        private readonly StarterSelectionService $starters,
    ) {}

    public function __invoke(SelectStarterRequest $request): JsonResponse
    {
        $owned = $this->starters->select($request->user(), (string) $request->validated('creature_slug'));

        return (new OwnedCreatureResource($owned))->response()->setStatusCode(201);
    }
}
