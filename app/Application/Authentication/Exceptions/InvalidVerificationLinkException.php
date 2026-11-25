<?php

namespace App\Application\Authentication\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class InvalidVerificationLinkException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('api.verification_link_invalid')], 403);
    }
}
