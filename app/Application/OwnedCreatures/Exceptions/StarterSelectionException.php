<?php

namespace App\Application\OwnedCreatures\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class StarterSelectionException extends RuntimeException
{
    private function __construct(string $message, private readonly string $translationKey, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function alreadySelected(): self
    {
        return new self('A starter creature was already selected.', 'api.starter_already_selected', 409);
    }

    public static function notAllowed(): self
    {
        return new self('The creature is not an allowed starter.', 'api.starter_not_allowed', 422);
    }

    public static function unavailable(): self
    {
        return new self('The starter creature is not available in the catalog.', 'api.starter_unavailable', 422);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => __($this->translationKey)], $this->status);
    }
}
