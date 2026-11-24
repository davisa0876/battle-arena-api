<?php

namespace App\Http\Controllers\Api;

use App\Application\Creatures\Services\CreatureCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatureIndexRequest;
use App\Http\Resources\CreatureResource;
use Illuminate\Http\JsonResponse;

class CreatureController extends Controller
{
    public function __construct(
        private readonly CreatureCatalogService $catalog,
    ) {}

    public function index(CreatureIndexRequest $request): JsonResponse
    {
        $paginator = $this->catalog->paginate($request->filters());

        return CreatureResource::collection($paginator)
            ->response();
    }

    public function show(string $slug): CreatureResource|JsonResponse
    {
        $creature = $this->catalog->findBySlug($slug);

        if ($creature === null) {
            return response()->json([
                'message' => __('api.creature_not_found'),
            ], 404);
        }

        return new CreatureResource($creature);
    }
}
