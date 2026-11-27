<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Battle\BattleEligibilityPolicy;
use App\Domain\Battle\BattleState;
use App\Domain\Battle\CombatSide;
use App\Domain\Battle\MoveSnapshot;
use App\Domain\Battle\OpponentIntent;
use App\Domain\Battle\Random\SeededRandomSource;
use App\Domain\Creatures\Support\TrustedCryUrl;
use App\Domain\OwnedCreatures\EvolutionEligibilityService;
use App\Domain\Progression\Models\PlayerProgress;
use App\Domain\Selection\SelectionEntitlementService;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesPlayers;
use Tests\TestCase;
use Tests\Unit\Domain\Battle\Support\BattleFixtures;

class RemainingDomainCoverageTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    #[Test]
    public function test_seeded_random_rejects_negative_cursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SeededRandomSource::fromSeed(1, -1);
    }

    #[Test]
    public function test_seeded_random_from_state_rejects_negative_cursor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SeededRandomSource::fromState(1, -1);
    }

    #[Test]
    public function test_trusted_cry_url_edge_hosts(): void
    {
        $this->assertNull(TrustedCryUrl::normalize('http:///bad'));
        $this->assertFalse(TrustedCryUrl::isTrusted('https:///PokeAPI/cries/x.ogg'));
        $this->assertFalse(TrustedCryUrl::isTrusted('https://evil.example/PokeAPI/cries/x.ogg'));
    }

    #[Test]
    public function test_evolution_eligibility_empty_owned_list(): void
    {
        $service = $this->app->make(EvolutionEligibilityService::class);
        $this->assertSame([], $service->optionsFor([]));
    }

    #[Test]
    public function test_battle_eligibility_for_progress_model(): void
    {
        $user = $this->makePlayer([], [], ['level' => 6, 'total_xp' => 1500]);
        $progress = PlayerProgress::query()->where('user_id', $user->id)->firstOrFail();
        $policy = $this->app->make(BattleEligibilityPolicy::class);
        $this->assertTrue($policy->canEnterPvpForProgress($progress));
    }

    #[Test]
    public function test_selection_entitlement_without_progress_and_consumed_count(): void
    {
        $user = User::factory()->create();
        $service = $this->app->make(SelectionEntitlementService::class);

        $allowances = $service->allowancesFor($user, null);
        $this->assertNotEmpty($allowances);
        $this->assertSame(0, $service->remainingForStage($user, 999, null));
        $this->assertFalse($service->pvpLevelUnlocked($user, null));
        $this->assertSame(0, $service->consumedCount($user, 1));
    }

    #[Test]
    public function test_pending_opponent_attack_rejects_unusable_move(): void
    {
        $rules = BattleFixtures::rules();
        $player = BattleFixtures::combatant(CombatSide::Player);
        $expensive = new MoveSnapshot(1, 'hyper', 'Hyper', 100, 100, 1, 1, 99, 'special', 'normal');
        $opponent = BattleFixtures::combatant(CombatSide::Opponent, moves: [$expensive], stamina: 0);
        $state = BattleState::start('id', $rules, $player, $opponent);
        $defending = $state->with(
            phase: \App\Domain\Battle\BattlePhase::AwaitingPlayerDefense,
            round: 1,
            version: 2,
            player: $player,
            opponent: $opponent,
            lastEventSequence: 1,
            outcome: null,
        );

        $this->expectException(DomainException::class);
        $defending->withPendingOpponentAttack(OpponentIntent::forMove(0, $expensive));
    }
}
