<?php

namespace App\Http\Controllers\Api\Auth;

use App\Application\Authentication\RegisterUserService;
use App\Application\Players\PlayerAccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\AuthenticatedUserResource;
use Illuminate\Http\JsonResponse;

class RegisterController extends Controller
{
    public function __construct(
        private readonly RegisterUserService $register,
        private readonly PlayerAccountService $accounts,
    ) {}

    public function __invoke(RegisterRequest $request): JsonResponse
    {
        $result = $this->register->register($request->payload(), $request);

        return (new AuthenticatedUserResource($this->accounts->load($result->user)))
            ->additional(['meta' => AuthMeta::from($result)])
            ->response()
            ->setStatusCode(201);
    }
}
