<?php

namespace App\Http\Controllers\Api\Pve;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\PveBattlePresenter;
use App\Application\Battles\SubmitPveDefenseService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pve\DefendBattleRequest;
use Illuminate\Http\JsonResponse;

final class DefendController extends Controller
{
    public function __invoke(
        DefendBattleRequest $request,
        string $battle,
        SubmitPveDefenseService $submit,
        PveBattlePresenter $presenter,
    ): JsonResponse {
        $key = (string) ($request->header('Idempotency-Key') ?: $request->validated('idempotency_key'));

        try {
            $result = $submit->submit(
                $request->user(),
                $battle,
                (string) $request->validated('defense'),
                (int) $request->validated('expected_version'),
                $key,
            );
        } catch (PveBattleException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->errorCode,
                'context' => $e->context,
            ], $e->status);
        }

        return response()->json([
            'data' => [
                'battle' => $presenter->present($result['battle'], $result['reward']),
                'events' => $result['events'],
                'reward' => $result['reward'],
            ],
        ]);
    }
}
