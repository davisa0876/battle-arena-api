<?php

namespace App\Http\Controllers\Api\Profile;

use App\Application\Players\AvatarUploadService;
use App\Application\Players\PlayerAccountService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\AvatarUploadRequest;
use App\Http\Resources\AuthenticatedUserResource;
use Illuminate\Http\Request;

class AvatarController extends Controller
{
    public function __construct(
        private readonly AvatarUploadService $avatars,
        private readonly PlayerAccountService $accounts,
    ) {}

    public function store(AvatarUploadRequest $request): AuthenticatedUserResource
    {
        $user = $request->user();
        $this->avatars->store($user, $request->file('avatar'));

        return new AuthenticatedUserResource($this->accounts->load($user->refresh()));
    }

    public function destroy(Request $request): AuthenticatedUserResource
    {
        $user = $request->user();
        $this->avatars->remove($user);

        return new AuthenticatedUserResource($this->accounts->load($user->refresh()));
    }
}
