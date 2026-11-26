<?php

namespace App\Http\Controllers\Api\Pvp\Concerns;

use Illuminate\Http\Request;

trait ResolvesPvpIdempotencyKey
{
    private function idempotencyKey(Request $request): ?string
    {
        $header = $request->header('Idempotency-Key');
        if (is_string($header) && $header !== '') {
            return $header;
        }

        $body = $request->input('idempotency_key');

        return is_string($body) && $body !== '' ? $body : null;
    }
}
