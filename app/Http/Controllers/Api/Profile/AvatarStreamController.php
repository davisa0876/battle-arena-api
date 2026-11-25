<?php

namespace App\Http\Controllers\Api\Profile;

use App\Application\Players\AvatarStreamService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AvatarStreamController extends Controller
{
    public function __construct(
        private readonly AvatarStreamService $avatars,
    ) {}

    /**
     * Avatars are profile photos shown on public profiles, so any holder of the URL may fetch them.
     */
    public function __invoke(int $userId): StreamedResponse|JsonResponse
    {
        $response = $this->avatars->respond($userId);

        return $response ?? response()->json(['message' => __('api.avatar_not_found')], 404);
    }
}
