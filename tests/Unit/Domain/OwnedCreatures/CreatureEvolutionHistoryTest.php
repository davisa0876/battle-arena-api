<?php

namespace Tests\Unit\Domain\OwnedCreatures;

use App\Domain\Creatures\Models\Creature;
use App\Domain\OwnedCreatures\Models\CreatureEvolutionHistory;
use App\Domain\OwnedCreatures\Models\OwnedCreature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\MakesPlayers;
use Tests\TestCase;

class CreatureEvolutionHistoryTest extends TestCase
{
    use MakesPlayers;
    use RefreshDatabase;

    #[Test]
    public function test_persists_casts_and_relations(): void
    {
        $user = $this->makePlayer();
        $from = Creature::factory()->create(['slug' => 'frommon']);
        $to = Creature::factory()->create(['slug' => 'tomon']);
        $owned = OwnedCreature::factory()->create([
            'user_id' => $user->id,
            'creature_id' => $from->id,
        ]);

        $history = CreatureEvolutionHistory::query()->create([
            'owned_creature_id' => $owned->id,
            'from_creature_id' => $from->id,
            'to_creature_id' => $to->id,
            'evolved_at' => now(),
        ]);

        $history->refresh();
        $this->assertTrue($history->evolved_at !== null);
        $this->assertTrue($history->ownedCreature->is($owned));
        $this->assertTrue($history->fromCreature->is($from));
        $this->assertTrue($history->toCreature->is($to));
    }
}
