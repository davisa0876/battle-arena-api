<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\LoginService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LogoutController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->login->logout($request->user(), $request);

        return response()->json(['message' => __('api.logged_out')]);
    }
}
