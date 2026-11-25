<?php

namespace App\Http\Controllers\Api\Pve;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\GetPveBattleService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class BattleEventsController extends Controller
{
    public function __invoke(
        Request $request,
        string $battle,
        GetPveBattleService $get,
    ): JsonResponse {
        try {
            $events = $get->eventsAfter($request->user(), $battle, (int) $request->query('after', 0));
        } catch (PveBattleException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->errorCode], $e->status);
        }

        return response()->json([
            'data' => array_map(static fn ($event) => [
                'sequence' => (int) $event->sequence,
                'type' => (string) $event->type,
                'actor' => $event->actor,
                'target' => $event->target,
                'payload' => $event->payload,
                'occurred_at' => optional($event->occurred_at)?->toIso8601String(),
            ], $events),
        ]);
    }
}
