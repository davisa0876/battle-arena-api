<?php

declare(strict_types=1);

namespace App\Application\Pvp\Exceptions;

use RuntimeException;

final class PvpLobbyException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
    ) {
        parent::__construct($message);
    }

    public static function notFound(): self
    {
        return new self('PVP_NOT_FOUND', __('api.pvp.not_found'), 404);
    }

    public static function forbidden(): self
    {
        return new self('PVP_FORBIDDEN', __('api.pvp.forbidden'), 403);
    }

    public static function notEligible(): self
    {
        return new self(
            'PVP_NOT_ELIGIBLE',
            __('api.pvp_locked', ['level' => (int) config('game.battle.pvp_min_trainer_level', 6)]),
            422,
        );
    }

    public static function selfInvite(): self
    {
        return new self('PVP_SELF_INVITE', __('api.pvp.self_invite'), 422);
    }

    public static function inviteeUnavailable(): self
    {
        return new self('PVP_INVITEE_UNAVAILABLE', __('api.pvp.invitee_unavailable'), 422);
    }

    public static function alreadyInLobby(): self
    {
        return new self('PVP_ALREADY_IN_LOBBY', __('api.pvp.already_in_lobby'), 409);
    }

    public static function inviteNotPending(): self
    {
        return new self('PVP_INVITE_NOT_PENDING', __('api.pvp.invite_not_pending'), 409);
    }

    public static function inviteExpired(): self
    {
        return new self('PVP_INVITE_EXPIRED', __('api.pvp.invite_expired'), 409);
    }

    public static function roomClosed(): self
    {
        return new self('PVP_ROOM_CLOSED', __('api.pvp.room_closed'), 409);
    }

    public static function idempotencyConflict(): self
    {
        return new self('IDEMPOTENCY_CONFLICT', __('api.pvp.idempotency_conflict'), 409);
    }

    public static function grantUnavailable(): self
    {
        return new self('PVP_GRANT_UNAVAILABLE', __('api.pvp.grant_unavailable'), 503);
    }

    public static function alreadyInBattle(): self
    {
        return new self('PVP_ALREADY_IN_BATTLE', __('api.pvp.already_in_battle'), 409);
    }

    public static function roomNotReady(): self
    {
        return new self('PVP_ROOM_NOT_READY', __('api.pvp.room_not_ready'), 409);
    }

    public static function selectionLocked(): self
    {
        return new self('PVP_SELECTION_LOCKED', __('api.pvp.selection_locked'), 409);
    }

    public static function wrongTurn(): self
    {
        return new self('PVP_WRONG_TURN', __('api.pvp.wrong_turn'), 409);
    }

    public static function phaseConflict(string $message = ''): self
    {
        return new self(
            'PVP_PHASE_CONFLICT',
            $message !== '' ? $message : __('api.pvp.phase_conflict'),
            409,
        );
    }

    public static function versionConflict(): self
    {
        return new self('PVP_VERSION_CONFLICT', __('api.pvp.version_conflict'), 409);
    }

    public static function alreadyFinished(): self
    {
        return new self('PVP_ALREADY_FINISHED', __('api.pvp.already_finished'), 409);
    }
}
