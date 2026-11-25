<?php

namespace App\Http\Controllers\Api\Profile;

use App\Application\Players\PlayerAccountService;
use App\Application\Players\UpdatePlayerProfileService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\AuthenticatedUserResource;

class UpdateProfileController extends Controller
{
    public function __construct(
        private readonly UpdatePlayerProfileService $profiles,
        private readonly PlayerAccountService $accounts,
    ) {}

    public function __invoke(UpdateProfileRequest $request): AuthenticatedUserResource
    {
        $user = $request->user();
        $this->profiles->update($user, $request->validated());

        return new AuthenticatedUserResource($this->accounts->load($user->refresh()));
    }
}
