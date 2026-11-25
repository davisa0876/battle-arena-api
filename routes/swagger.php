<?php

use App\Services\SwaggerDocsService;
use Illuminate\Support\Facades\Route;

Route::get('/swagger.json', function (SwaggerDocsService $service) {
    return response()->json($service->buildConfig(
        title: config('app.name', 'Battle Arena API'),
        basePath: 'resources/docs/swagger',
        servers: [
            ['url' => url('/'), 'description' => 'Current host'],
        ],
        infoDescription: 'Battle Arena HTTP contract. Fragment source: resources/docs/swagger (Horus-style).',
    ));
})->name('api.swagger.json');
