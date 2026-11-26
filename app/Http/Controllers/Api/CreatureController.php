<?php

namespace App\Http\Controllers\Api;

use App\Application\Creatures\Services\CreatureCatalogService;
use App\Application\Creatures\Services\CreatureCombatPreviewService;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatureIndexRequest;
use App\Http\Resources\CreatureResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CreatureController extends Controller
{
    public function __construct(
        private readonly CreatureCatalogService $catalog,
        private readonly CreatureCombatPreviewService $combatPreview,
    ) {}

    public function index(CreatureIndexRequest $request): JsonResponse
    {
        $paginator = $this->catalog->paginate($request->filters());

        return CreatureResource::collection($paginator)
            ->response();
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $creature = $this->catalog->findBySlug($slug);

        if ($creature === null) {
            return response()->json([
                'message' => __('api.creature_not_found'),
            ], 404);
        }

        $data = (new CreatureResource($creature))->resolve($request);
        $data['combat'] = $this->combatPreview->forCreature($creature);

        return response()->json(['data' => $data]);
    }
}
