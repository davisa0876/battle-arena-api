<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\LoginService;
use App\Application\Players\PlayerAccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __construct(
        private readonly LoginService $login,
        private readonly PlayerAccountService $accounts,
    ) {}

    public function __invoke(LoginRequest $request): JsonResponse
    {
        $result = $this->login->login(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request->validated('device_name'),
            $request,
        );

        return (new AuthenticatedUserResource($this->accounts->load($result->user)))
            ->additional(['meta' => AuthMeta::from($result)])
            ->response();
    }
}
