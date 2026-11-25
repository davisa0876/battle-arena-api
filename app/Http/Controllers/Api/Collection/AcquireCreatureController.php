<?php

namespace App\Http\Controllers\Api\Collection;

use App\Application\OwnedCreatures\CreatureAcquisitionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Collection\AcquireCreatureRequest;
use App\Http\Resources\OwnedCreatureResource;
use Illuminate\Http\JsonResponse;

class AcquireCreatureController extends Controller
{
    public function __construct(
        private readonly CreatureAcquisitionService $acquisitions,
    ) {}

    public function __invoke(AcquireCreatureRequest $request): JsonResponse
    {
        $owned = $this->acquisitions->acquireFromCatalog(
            $request->user(),
            (string) $request->validated('creature_slug'),
            (string) $request->validated('idempotency_key'),
        );

        return (new OwnedCreatureResource($owned))->response()->setStatusCode(201);
    }
}
