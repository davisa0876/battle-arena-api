<?php

namespace Tests\Unit\Application\Pvp;

use App\Application\Pvp\Exceptions\PvpLobbyException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PvpLobbyExceptionTest extends TestCase
{
    #[Test]
    public function test_all_factory_helpers_emit_expected_codes(): void
    {
        $cases = [
            [PvpLobbyException::notFound(), 'PVP_NOT_FOUND', 404],
            [PvpLobbyException::forbidden(), 'PVP_FORBIDDEN', 403],
            [PvpLobbyException::notEligible(), 'PVP_NOT_ELIGIBLE', 422],
            [PvpLobbyException::selfInvite(), 'PVP_SELF_INVITE', 422],
            [PvpLobbyException::inviteeUnavailable(), 'PVP_INVITEE_UNAVAILABLE', 422],
            [PvpLobbyException::alreadyInLobby(), 'PVP_ALREADY_IN_LOBBY', 409],
            [PvpLobbyException::inviteNotPending(), 'PVP_INVITE_NOT_PENDING', 409],
            [PvpLobbyException::inviteExpired(), 'PVP_INVITE_EXPIRED', 409],
            [PvpLobbyException::roomClosed(), 'PVP_ROOM_CLOSED', 409],
            [PvpLobbyException::idempotencyConflict(), 'IDEMPOTENCY_CONFLICT', 409],
            [PvpLobbyException::grantUnavailable(), 'PVP_GRANT_UNAVAILABLE', 503],
            [PvpLobbyException::alreadyInBattle(), 'PVP_ALREADY_IN_BATTLE', 409],
            [PvpLobbyException::roomNotReady(), 'PVP_ROOM_NOT_READY', 409],
            [PvpLobbyException::selectionLocked(), 'PVP_SELECTION_LOCKED', 409],
            [PvpLobbyException::wrongTurn(), 'PVP_WRONG_TURN', 409],
            [PvpLobbyException::phaseConflict('x'), 'PVP_PHASE_CONFLICT', 409],
            [PvpLobbyException::phaseConflict(), 'PVP_PHASE_CONFLICT', 409],
            [PvpLobbyException::versionConflict(), 'PVP_VERSION_CONFLICT', 409],
            [PvpLobbyException::alreadyFinished(), 'PVP_ALREADY_FINISHED', 409],
        ];

        foreach ($cases as [$exception, $code, $status]) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
