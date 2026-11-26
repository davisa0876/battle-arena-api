<?php

namespace App\Application\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use App\Domain\Pvp\Models\PvpRequestReplay;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PvpIdempotencyStore
{
    /**
     * @param  array<string, mixed>  $fingerprint
     * @param  callable(): array{status:int, body:array<string, mixed>}  $callback
     * @return array{status:int, body:array<string, mixed>}
     */
    public function remember(User $user, string $operation, ?string $idempotencyKey, array $fingerprint, callable $callback): array
    {
        if ($idempotencyKey === null || $idempotencyKey === '') {
            return $callback();
        }

        return DB::transaction(function () use ($user, $operation, $idempotencyKey, $fingerprint, $callback): array {
            $existing = PvpRequestReplay::query()
                ->where('user_id', $user->id)
                ->where('operation', $operation)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint) {
                    throw PvpLobbyException::idempotencyConflict();
                }

                return [
                    'status' => (int) $existing->response_status,
                    'body' => $existing->response_body,
                ];
            }

            $result = $callback();

            PvpRequestReplay::query()->create([
                'user_id' => $user->id,
                'operation' => $operation,
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'response_status' => $result['status'],
                'response_body' => $result['body'],
            ]);

            return $result;
        });
    }
}
