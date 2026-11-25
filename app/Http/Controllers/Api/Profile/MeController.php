<?php

namespace App\Http\Controllers\Api\Profile;

use App\Application\Players\PlayerAccountService;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuthenticatedUserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __construct(
        private readonly PlayerAccountService $accounts,
    ) {}

    public function show(Request $request): AuthenticatedUserResource
    {
        return new AuthenticatedUserResource($this->accounts->load($request->user()));
    }
}
