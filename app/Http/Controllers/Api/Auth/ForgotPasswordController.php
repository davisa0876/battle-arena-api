<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\PasswordRecoveryService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;

class ForgotPasswordController extends Controller
{
    public function __construct(
        private readonly PasswordRecoveryService $recovery,
    ) {}

    public function __invoke(ForgotPasswordRequest $request): JsonResponse
    {
        $this->recovery->sendResetLink((string) $request->validated('email'));

        // Same answer whether or not the account exists.
        return response()->json(['message' => __('api.password_reset_link_sent')]);
    }
}
