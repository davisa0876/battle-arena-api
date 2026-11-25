<?php

namespace App\Application\OwnedCreatures\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

class CreatureSelectionException extends RuntimeException
{
    private function __construct(string $message, private readonly string $translationKey, private readonly int $status)
    {
        parent::__construct($message);
    }

    public static function noAllowance(int $stage): self
    {
        return new self(
            "No remaining stage-{$stage} selection entitlement.",
            'api.selection_no_allowance',
            403,
        );
    }

    public static function stageLocked(int $stage): self
    {
        return new self(
            "Stage-{$stage} selection is not available.",
            'api.selection_stage_locked',
            403,
        );
    }

    public static function alreadyOwned(): self
    {
        return new self('You already own this creature.', 'api.selection_already_owned', 409);
    }

    public static function unavailable(): self
    {
        return new self('The creature is not available in the catalog.', 'api.selection_unavailable', 422);
    }

    public static function duplicateRequest(): self
    {
        return new self('This selection request was already processed.', 'api.selection_duplicate', 409);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => __($this->translationKey)], $this->status);
    }
}
