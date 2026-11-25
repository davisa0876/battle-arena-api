<?php

namespace App\Http\Controllers\Api\Profile;

use App\Application\Players\PublicPlayerService;
use App\Http\Controllers\Controller;
use App\Http\Resources\PublicPlayerResource;
use Illuminate\Http\JsonResponse;

class PublicPlayerController extends Controller
{
    public function __construct(
        private readonly PublicPlayerService $players,
    ) {}

    public function show(string $handle): PublicPlayerResource|JsonResponse
    {
        $profile = $this->players->findByHandle($handle);

        if ($profile === null) {
            return response()->json(['message' => __('api.player_not_found')], 404);
        }

        return new PublicPlayerResource($profile);
    }
}
