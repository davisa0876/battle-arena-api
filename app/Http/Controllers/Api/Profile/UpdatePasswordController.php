<?php

namespace App\Http\Controllers\Api\Profile;

use App\Application\Authentication\UpdatePasswordService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use Illuminate\Http\JsonResponse;

class UpdatePasswordController extends Controller
{
    public function __construct(
        private readonly UpdatePasswordService $passwords,
    ) {}

    public function __invoke(UpdatePasswordRequest $request): JsonResponse
    {
        $this->passwords->update($request->user(), (string) $request->validated('password'));

        return response()->json(['message' => __('api.password_updated')]);
    }
}
