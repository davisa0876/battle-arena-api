<?php

namespace App\Http\Controllers\Api;

use App\Application\Game\GameRulesService;
use App\Http\Controllers\Controller;
use App\Http\Resources\GameRulesResource;

class GameRulesController extends Controller
{
    public function __construct(
        private readonly GameRulesService $rules,
    ) {}

    public function __invoke(): GameRulesResource
    {
        return new GameRulesResource($this->rules->rules());
    }
}
