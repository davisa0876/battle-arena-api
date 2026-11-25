<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\PasswordRecoveryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\JsonResponse;

class ResetPasswordController extends Controller
{
    public function __construct(
        private readonly PasswordRecoveryService $recovery,
    ) {}

    public function __invoke(ResetPasswordRequest $request): JsonResponse
    {
        $this->recovery->reset(
            (string) $request->validated('email'),
            (string) $request->validated('token'),
            (string) $request->validated('password'),
        );

        return response()->json(['message' => __('passwords.reset')]);
    }
}
