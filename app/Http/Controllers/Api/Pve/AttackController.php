<?php

namespace App\Http\Controllers\Api\Pve;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\PveBattlePresenter;
use App\Application\Battles\SubmitPveAttackService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pve\AttackBattleRequest;
use Illuminate\Http\JsonResponse;

final class AttackController extends Controller
{
    public function __invoke(
        AttackBattleRequest $request,
        string $battle,
        SubmitPveAttackService $submit,
        PveBattlePresenter $presenter,
    ): JsonResponse {
        $key = (string) ($request->header('Idempotency-Key') ?: $request->validated('idempotency_key'));

        try {
            $result = $submit->submit(
                $request->user(),
                $battle,
                isset($request->validated()['move_id']) ? (int) $request->validated('move_id') : null,
                (bool) $request->boolean('basic_strike'),
                (bool) $request->boolean('recover'),
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
