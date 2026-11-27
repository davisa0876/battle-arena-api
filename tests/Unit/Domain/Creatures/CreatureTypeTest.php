<?php

namespace Tests\Unit\Domain\Creatures;

use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreatureTypeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function test_creatures_relation_and_factory(): void
    {
        $type = CreatureType::factory()->create(['slug' => 'fire', 'name' => 'Fire']);
        $creature = Creature::factory()->create();
        $type->creatures()->attach($creature->id, ['slot' => 1]);

        $this->assertTrue($type->creatures()->whereKey($creature->id)->exists());
        $this->assertSame(1, (int) $type->creatures()->first()->pivot->slot);
        $this->assertInstanceOf(CreatureType::class, CreatureType::factory()->make());
    }
}
