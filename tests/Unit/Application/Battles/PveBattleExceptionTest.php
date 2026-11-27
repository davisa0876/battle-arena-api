<?php

namespace Tests\Unit\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PveBattleExceptionTest extends TestCase
{
    #[Test]
    public function test_all_factory_helpers(): void
    {
        $this->assertSame('BATTLE_NOT_FOUND', PveBattleException::notFound()->errorCode);
        $this->assertSame(404, PveBattleException::notFound()->status);

        $phase = PveBattleException::phaseConflict('custom');
        $this->assertSame('BATTLE_PHASE_CONFLICT', $phase->errorCode);
        $this->assertSame('custom', $phase->getMessage());
        $this->assertSame(409, $phase->status);

        $this->assertSame('BATTLE_VERSION_CONFLICT', PveBattleException::versionConflict()->errorCode);
        $this->assertSame('MOVE_NOT_AVAILABLE', PveBattleException::moveNotAvailable('nope')->errorCode);
        $this->assertSame('INSUFFICIENT_STAMINA', PveBattleException::insufficientStamina()->errorCode);
        $this->assertSame('IDEMPOTENCY_CONFLICT', PveBattleException::idempotencyConflict()->errorCode);

        $eligible = PveBattleException::notEligible('locked');
        $this->assertSame('BATTLE_NOT_ELIGIBLE', $eligible->errorCode);
        $this->assertSame(['x' => 1], (new PveBattleException('CODE', 'msg', 418, ['x' => 1]))->context);
    }
}
