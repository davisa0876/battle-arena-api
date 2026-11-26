<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Battle;

use App\Domain\Battle\BattleRules;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\CombatantSnapshot;
use App\Domain\Battle\DefenseType;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\Pvp\PvpBattlePhase;
use App\Domain\Battle\Pvp\PvpBattleState;
use App\Domain\Battle\Pvp\PvpTurnEngine;
use App\Domain\Battle\Random\SeededRandomSource;
use App\Domain\Battle\TypeEffectivenessChart;
use PHPUnit\Framework\TestCase;

final class PvpTurnEngineTest extends TestCase
{
    public function test_commit_attack_does_not_spend_resources_until_defense(): void
    {
        $state = $this->freshState(attackerUserId: 1, defenderUserId: 2);
        $engine = new PvpTurnEngine;
        $move = $state->host->move(0);

        $committed = $engine->commitAttack($state, 1, 0, $move, 0);

        $this->assertSame(PvpBattlePhase::AwaitingDefense, $committed->nextState->phase);
        $this->assertSame($state->host->hpCurrent, $committed->nextState->host->hpCurrent);
        $this->assertSame($state->host->staminaCurrent, $committed->nextState->host->staminaCurrent);
        $this->assertSame($state->host->move(0)->ppCurrent, $committed->nextState->host->move(0)->ppCurrent);
        $this->assertNotNull($committed->nextState->pendingAttack);

        $rng = SeededRandomSource::fromSeed(1, 0);
        $resolved = $engine->resolveDefense(
            $committed->nextState,
            2,
            DefenseType::Endure,
            $rng,
            new TypeEffectivenessChart([]),
        );

        $this->assertTrue(
            $resolved->nextState->host->staminaCurrent < $state->host->staminaCurrent
            || $resolved->nextState->host->move(0)->ppCurrent < $state->host->move(0)->ppCurrent
            || $resolved->nextState->guest->hpCurrent < $state->guest->hpCurrent
            || $resolved->nextState->isFinished(),
        );
        $this->assertNull($resolved->nextState->pendingAttack);
    }

    public function test_recover_skip_switches_attacker_without_pending_defense(): void
    {
        $state = $this->freshState(attackerUserId: 1, defenderUserId: 2, stamina: 5);
        $engine = new PvpTurnEngine;

        $next = $engine->recoverSkip($state, 1, 0);

        $this->assertSame(PvpBattlePhase::AwaitingAttack, $next->nextState->phase);
        $this->assertSame(2, $next->nextState->currentAttackerUserId);
        $this->assertSame(1, $next->nextState->currentDefenderUserId);
        $this->assertNull($next->nextState->pendingAttack);
        $this->assertGreaterThan($state->host->staminaCurrent, $next->nextState->host->staminaCurrent);
    }

    public function test_ko_sets_winner_and_loser_user_ids(): void
    {
        $state = $this->freshState(attackerUserId: 10, defenderUserId: 20, defenderHp: 1);
        $engine = new PvpTurnEngine;
        $move = $state->host->move(0);
        $committed = $engine->commitAttack($state, 10, 0, $move, 0);
        $rng = SeededRandomSource::fromSeed(42, 0);
        $resolved = $engine->resolveDefense(
            $committed->nextState,
            20,
            DefenseType::Endure,
            $rng,
            new TypeEffectivenessChart([]),
        );

        $this->assertTrue($resolved->nextState->isFinished());
        $this->assertSame(10, $resolved->nextState->winnerUserId);
        $this->assertSame(20, $resolved->nextState->loserUserId);
    }

    private function freshState(
        int $attackerUserId,
        int $defenderUserId,
        int $stamina = 100,
        int $defenderHp = 40,
    ): PvpBattleState {
        $hostId = min($attackerUserId, $defenderUserId) === $attackerUserId
            ? ($attackerUserId === 10 ? 10 : 1)
            : ($defenderUserId === 20 ? 10 : 1);
        // Keep host=1/guest=2 mapping for first two tests; override for KO test.
        if ($attackerUserId === 10) {
            $hostId = 10;
            $guestId = 20;
        } else {
            $hostId = 1;
            $guestId = 2;
        }

        $move = new MoveSnapshot(
            id: 1,
            slug: 'ember',
            name: 'Ember',
            power: 40,
            accuracy: 100,
            ppCurrent: 10,
            ppMax: 10,
            staminaCost: 10,
            damageClass: 'special',
            typeSlug: 'fire',
        );

        $host = new CombatantSnapshot(
            side: CombatSide::Player,
            name: 'Host',
            slug: 'host',
            level: 5,
            types: ['fire'],
            hpCurrent: 40,
            hpMax: 40,
            staminaCurrent: $hostId === $attackerUserId ? $stamina : 100,
            staminaMax: 100,
            stats: ['attack' => 20, 'defense' => 15, 'special_attack' => 25, 'special_defense' => 15, 'speed' => 20],
            moves: [$move],
        );
        $guest = new CombatantSnapshot(
            side: CombatSide::Opponent,
            name: 'Guest',
            slug: 'guest',
            level: 5,
            types: ['grass'],
            hpCurrent: $guestId === $defenderUserId ? $defenderHp : 40,
            hpMax: 40,
            staminaCurrent: $guestId === $attackerUserId ? $stamina : 100,
            staminaMax: 100,
            stats: ['attack' => 20, 'defense' => 15, 'special_attack' => 20, 'special_defense' => 15, 'speed' => 18],
            moves: [$move],
        );

        return new PvpBattleState(
            id: '11111111-1111-4111-8111-111111111111',
            roomId: '22222222-2222-4222-8222-222222222222',
            phase: PvpBattlePhase::AwaitingAttack,
            turnNumber: 1,
            version: 1,
            rules: BattleRules::fromArray([]),
            hostUserId: $hostId,
            guestUserId: $guestId,
            host: $host,
            guest: $guest,
            currentAttackerUserId: $attackerUserId,
            currentDefenderUserId: $defenderUserId,
        );
    }
}
