<?php

declare(strict_types=1);

namespace App\Application\Battles\Exceptions;

use RuntimeException;

final class PveBattleException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly ?array $context = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('BATTLE_NOT_FOUND', 'Battle not found.', 404);
    }

    public static function phaseConflict(string $message = 'Battle phase conflict.'): self
    {
        return new self('BATTLE_PHASE_CONFLICT', $message, 409);
    }

    public static function versionConflict(): self
    {
        return new self('BATTLE_VERSION_CONFLICT', 'Stale battle version.', 409);
    }

    public static function moveNotAvailable(string $message = 'Move is not available.'): self
    {
        return new self('MOVE_NOT_AVAILABLE', $message, 422);
    }

    public static function insufficientStamina(): self
    {
        return new self('INSUFFICIENT_STAMINA', 'Not enough stamina for this action.', 422);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_CONFLICT', 'Idempotency key was reused with a different payload.', 409);
    }

    public static function notEligible(string $message): self
    {
        return new self('BATTLE_NOT_ELIGIBLE', $message, 422);
    }
}
