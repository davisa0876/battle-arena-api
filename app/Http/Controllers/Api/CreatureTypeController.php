<?php

namespace App\Http\Controllers\Api;

use App\Application\Creatures\Services\CreatureCatalogService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CreatureTypeResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CreatureTypeController extends Controller
{
    public function __construct(
        private readonly CreatureCatalogService $catalog,
    ) {}

    public function index(): AnonymousResourceCollection
    {
        return CreatureTypeResource::collection($this->catalog->listTypes());
    }
}
