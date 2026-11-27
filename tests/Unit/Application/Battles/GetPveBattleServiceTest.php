<?php

namespace Tests\Unit\Application\Battles;

use App\Application\Battles\Exceptions\PveBattleException;
use App\Application\Battles\GetPveBattleService;
use App\Domain\Battle\Models\BattleEventRecord;
use App\Domain\Battle\Models\PveBattle;
use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class GetPveBattleServiceTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    #[Test]
    public function test_owned_battle_not_found_for_other_user_or_missing_id(): void
    {
        $other = $this->makePlayer(['handle' => 'otherguy']);
        $service = $this->app->make(GetPveBattleService::class);

        $this->expectException(PveBattleException::class);
        $service->ownedBattle($other, (string) Str::uuid());
    }

    #[Test]
    public function test_events_after_returns_ordered_records(): void
    {
        $owner = $this->makePlayer();
        $creature = Creature::factory()->create();
        $owned = OwnedCreature::factory()->create([
            'user_id' => $owner->id,
            'creature_id' => $creature->id,
        ]);
        $battle = PveBattle::query()->create([
            'user_id' => $owner->id,
            'owned_creature_id' => $owned->id,
            'status' => PveBattle::STATUS_ACTIVE,
            'phase' => 'awaiting_player_attack',
            'round' => 1,
            'version' => 1,
            'rules_version' => 'pve-0.1',
            'difficulty' => 'normal',
            'rng_seed' => 'seed',
            'rng_cursor' => 0,
        ]);
        BattleEventRecord::query()->create([
            'battle_id' => $battle->id,
            'sequence' => 1,
            'type' => 'round_started',
            'payload' => [],
            'occurred_at' => now(),
        ]);
        BattleEventRecord::query()->create([
            'battle_id' => $battle->id,
            'sequence' => 2,
            'type' => 'attack_resolved',
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $service = $this->app->make(GetPveBattleService::class);
        $events = $service->eventsAfter($owner, (string) $battle->id, 1);

        $this->assertCount(1, $events);
        $this->assertSame(2, $events[0]->sequence);
    }
}
