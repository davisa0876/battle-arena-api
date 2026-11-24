<?php

namespace Tests\Feature\Application\Creatures;

use App\Application\Creatures\Contracts\CreatureCatalogProvider;
use App\Application\Creatures\DTOs\ExternalCreatureData;
use App\Application\Creatures\Exceptions\CreatureProviderException;
use App\Application\Creatures\Services\CreatureImportService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreatureImportServiceTest extends TestCase
{
    use RefreshDatabase;

    private const IMAGE_A = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/1.png';

    private const IMAGE_B = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/1.png';

    /** @var CreatureCatalogProvider&MockInterface */
    private $provider;

    private CreatureImportService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = Mockery::mock(CreatureCatalogProvider::class);
        $this->provider->shouldReceive('providerName')->andReturn('pokeapi')->byDefault();
        $this->service = new CreatureImportService($this->provider);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function dto(int $externalId, array $overrides = []): ExternalCreatureData
    {
        $defaults = [
            'provider' => 'pokeapi',
            'externalId' => $externalId,
            'name' => "Creature {$externalId}",
            'slug' => "creature-{$externalId}",
            'heightCm' => 100,
            'weightG' => 5000,
            'baseHp' => 45,
            'baseAttack' => 49,
            'baseDefense' => 49,
            'baseSpecialAttack' => 65,
            'baseSpecialDefense' => 65,
            'baseSpeed' => 45,
            'types' => [
                ['name' => 'Grass', 'slug' => 'grass', 'slot' => 1],
            ],
            'imageRef' => self::IMAGE_A,
        ];

        return new ExternalCreatureData(...array_merge($defaults, $overrides));
    }

    /**
     * @param  list<ExternalCreatureData>  $payloads
     */
    private function provideForAnyIds(array $payloads): void
    {
        $this->provider->shouldReceive('fetchByExternalIds')->andReturn($payloads);
    }

    // ------------------------------------------------------------ id handling

    #[Test]
    public function test_null_ids_use_the_default_catalog(): void
    {
        $this->provider->shouldReceive('fetchByExternalIds')
            ->once()
            ->with(CreatureImportService::DEFAULT_EXTERNAL_IDS)
            ->andReturn([]);

        $summary = $this->service->import();

        $this->assertSame(0, $summary['imported']);
        // Every default id is reported missing because the provider returned nothing.
        $this->assertSame(count(CreatureImportService::DEFAULT_EXTERNAL_IDS), $summary['failed']);
    }

    #[Test]
    public function test_default_catalog_is_twenty_unique_positive_ids(): void
    {
        $ids = CreatureImportService::DEFAULT_EXTERNAL_IDS;

        $this->assertCount(20, $ids);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame([], array_filter($ids, fn (int $id) => $id < 1));
    }

    #[Test]
    public function test_explicit_ids_are_passed_to_the_provider(): void
    {
        $this->provider->shouldReceive('fetchByExternalIds')
            ->once()
            ->with([4, 1])
            ->andReturn([$this->dto(4), $this->dto(1)]);

        $summary = $this->service->import([4, 1]);

        $this->assertSame(2, $summary['imported']);
    }

    #[Test]
    public function test_ids_are_cast_to_int_and_deduplicated_preserving_first_occurrence(): void
    {
        $this->provider->shouldReceive('fetchByExternalIds')
            ->once()
            ->with([2, 1, 3])
            ->andReturn([$this->dto(1), $this->dto(2), $this->dto(3)]);

        /** @phpstan-ignore-next-line intentionally loose input as it may come from CLI strings */
        $summary = $this->service->import(['2', 1, '1', 2, '3']);

        $this->assertSame(3, $summary['imported']);
        $this->assertDatabaseCount('creatures', 3);
    }

    #[Test]
    public function test_empty_id_list_is_not_replaced_by_defaults(): void
    {
        $this->provider->shouldReceive('fetchByExternalIds')->once()->with([])->andReturn([]);

        $summary = $this->service->import([]);

        $this->assertSame(['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []], $summary);
    }

    // --------------------------------------------------------------- outcomes

    #[Test]
    public function test_missing_payload_is_counted_as_failed_with_message(): void
    {
        $this->provideForAnyIds([$this->dto(1)]);

        $summary = $this->service->import([1, 2]);

        $this->assertSame(1, $summary['imported']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(['Missing provider payload for external id 2.'], $summary['errors']);
        $this->assertDatabaseMissing('creatures', ['external_id' => 2]);
    }

    #[Test]
    public function test_incomplete_payload_is_skipped_and_not_persisted(): void
    {
        $this->provideForAnyIds([$this->dto(1, ['baseHp' => 0])]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(0, $summary['imported']);
        $this->assertSame(0, $summary['failed']);
        $this->assertCount(1, $summary['errors']);
        $this->assertStringContainsString('Incomplete provider data for pokeapi:1', $summary['errors'][0]);
        $this->assertDatabaseCount('creatures', 0);
        $this->assertDatabaseCount('creature_types', 0);
    }

    #[Test]
    public function test_incomplete_payload_leaves_existing_creature_untouched(): void
    {
        Creature::factory()->create([
            'provider' => 'pokeapi', 'external_id' => 1, 'name' => 'Keeper', 'slug' => 'keeper', 'base_hp' => 100,
        ]);
        $this->provideForAnyIds([$this->dto(1, ['types' => []])]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['skipped']);
        $this->assertDatabaseHas('creatures', ['slug' => 'keeper', 'name' => 'Keeper', 'base_hp' => 100]);
    }

    #[Test]
    public function test_mixed_batch_reports_each_outcome(): void
    {
        Creature::factory()->create([
            'provider' => 'pokeapi', 'external_id' => 2, 'name' => 'Old', 'slug' => 'creature-2',
        ]);
        $this->provideForAnyIds([
            $this->dto(1),
            $this->dto(2),
            $this->dto(3, ['slug' => '']),
        ]);

        $summary = $this->service->import([1, 2, 3, 4]);

        $this->assertSame(1, $summary['imported']);
        $this->assertSame(1, $summary['updated']);
        $this->assertSame(1, $summary['skipped']);
        $this->assertSame(1, $summary['failed']);
        $this->assertCount(2, $summary['errors']);
    }

    #[Test]
    public function test_persistence_failure_is_counted_as_failed_and_does_not_abort_batch(): void
    {
        Log::shouldReceive('error')->once();
        // Same slug on a different external id violates the unique slug index.
        $this->provideForAnyIds([
            $this->dto(1, ['slug' => 'shared-slug']),
            $this->dto(2, ['slug' => 'shared-slug']),
            $this->dto(3),
        ]);

        $summary = $this->service->import([1, 2, 3]);

        $this->assertSame(2, $summary['imported']);
        $this->assertSame(1, $summary['failed']);
        $this->assertStringContainsString('Failed to persist external id 2', $summary['errors'][0]);
        $this->assertDatabaseMissing('creatures', ['external_id' => 2]);
        $this->assertDatabaseHas('creatures', ['external_id' => 3]);
    }

    #[Test]
    public function test_provider_exception_is_rethrown_without_writing_anything(): void
    {
        Log::shouldReceive('warning')->once()->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'aborted')
                && $context['provider'] === 'pokeapi'
                && $context['message'] === 'upstream down';
        });
        $this->provider->shouldReceive('fetchByExternalIds')->andThrow(new CreatureProviderException('upstream down'));
        Creature::factory()->create(['provider' => 'pokeapi', 'external_id' => 1, 'name' => 'Existing', 'slug' => 'existing']);

        try {
            $this->service->import([1, 2]);
            $this->fail('Expected CreatureProviderException.');
        } catch (CreatureProviderException $exception) {
            $this->assertSame('upstream down', $exception->getMessage());
        }

        $this->assertDatabaseCount('creatures', 1);
        $this->assertDatabaseCount('creature_types', 0);
        $this->assertDatabaseHas('creatures', ['slug' => 'existing', 'name' => 'Existing']);
    }

    #[Test]
    public function test_non_provider_exceptions_from_provider_are_not_swallowed(): void
    {
        $this->provider->shouldReceive('fetchByExternalIds')->andThrow(new \LogicException('bug'));

        $this->expectException(\LogicException::class);

        $this->service->import([1]);
    }

    // ----------------------------------------------------------- new imports

    #[Test]
    public function test_new_import_persists_all_attributes(): void
    {
        $this->provideForAnyIds([$this->dto(25, [
            'name' => 'Pikachu',
            'slug' => 'pikachu',
            'heightCm' => 40,
            'weightG' => 6000,
            'baseHp' => 35,
            'baseAttack' => 55,
            'baseDefense' => 40,
            'baseSpecialAttack' => 50,
            'baseSpecialDefense' => 50,
            'baseSpeed' => 90,
            'imageRef' => self::IMAGE_B,
        ])]);

        $summary = $this->service->import([25]);

        $this->assertSame(1, $summary['imported']);
        $this->assertDatabaseHas('creatures', [
            'provider' => 'pokeapi',
            'external_id' => 25,
            'name' => 'Pikachu',
            'slug' => 'pikachu',
            'height_cm' => 40,
            'weight_g' => 6000,
            'base_hp' => 35,
            'base_attack' => 55,
            'base_defense' => 40,
            'base_special_attack' => 50,
            'base_special_defense' => 50,
            'base_speed' => 90,
            'image_ref' => self::IMAGE_B,
        ]);
    }

    #[Test]
    public function test_new_import_with_null_image_and_dimensions_persists_nulls(): void
    {
        $this->provideForAnyIds([$this->dto(1, ['imageRef' => null, 'heightCm' => null, 'weightG' => null])]);

        $this->service->import([1]);

        $creature = Creature::query()->firstOrFail();
        $this->assertNull($creature->image_ref);
        $this->assertNull($creature->height_cm);
        $this->assertNull($creature->weight_g);
    }

    #[Test]
    public function test_new_import_syncs_types_with_slots(): void
    {
        $this->provideForAnyIds([$this->dto(1, [
            'types' => [
                ['name' => 'Grass', 'slug' => 'grass', 'slot' => 1],
                ['name' => 'Poison', 'slug' => 'poison', 'slot' => 2],
            ],
        ])]);

        $this->service->import([1]);

        $creature = Creature::query()->with('types')->firstOrFail();
        $this->assertSame(['grass', 'poison'], $creature->types->pluck('slug')->all());
        $this->assertSame([1, 2], $creature->types->pluck('pivot.slot')->all());
        $this->assertSame(['Grass', 'Poison'], $creature->types->pluck('name')->all());
    }

    #[Test]
    public function test_types_are_returned_ordered_by_slot_even_if_payload_order_differs(): void
    {
        $this->provideForAnyIds([$this->dto(1, [
            'types' => [
                ['name' => 'Poison', 'slug' => 'poison', 'slot' => 2],
                ['name' => 'Grass', 'slug' => 'grass', 'slot' => 1],
            ],
        ])]);

        $this->service->import([1]);

        $creature = Creature::query()->with('types')->firstOrFail();
        $this->assertSame(['grass', 'poison'], $creature->types->pluck('slug')->all());
    }

    #[Test]
    public function test_existing_creature_type_is_reused_not_duplicated(): void
    {
        CreatureType::factory()->create(['name' => 'Grass (legacy)', 'slug' => 'grass']);
        $this->provideForAnyIds([$this->dto(1), $this->dto(2)]);

        $this->service->import([1, 2]);

        $this->assertSame(1, CreatureType::query()->where('slug', 'grass')->count());
        $this->assertSame('Grass (legacy)', CreatureType::query()->where('slug', 'grass')->value('name'));
        $this->assertSame(2, Creature::query()->count());
        $this->assertDatabaseCount('creature_type', 2);
    }

    // --------------------------------------------------------------- updates

    #[Test]
    public function test_update_overwrites_catalog_attributes(): void
    {
        $existing = Creature::factory()->create([
            'provider' => 'pokeapi', 'external_id' => 1, 'name' => 'Old', 'slug' => 'old',
            'base_hp' => 1, 'base_speed' => 1, 'height_cm' => 1, 'weight_g' => 1,
        ]);
        $this->provideForAnyIds([$this->dto(1, [
            'name' => 'New', 'slug' => 'new', 'baseHp' => 99, 'baseSpeed' => 88, 'heightCm' => 200, 'weightG' => 9000,
        ])]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(0, $summary['imported']);
        $existing->refresh();
        $this->assertSame('New', $existing->name);
        $this->assertSame('new', $existing->slug);
        $this->assertSame(99, $existing->base_hp);
        $this->assertSame(88, $existing->base_speed);
        $this->assertSame(200, $existing->height_cm);
        $this->assertSame(9000, $existing->weight_g);
        $this->assertDatabaseCount('creatures', 1);
    }

    #[Test]
    public function test_update_matches_on_provider_and_external_id_pair(): void
    {
        Creature::factory()->create(['provider' => 'otherapi', 'external_id' => 1, 'name' => 'Other', 'slug' => 'other']);
        $this->provideForAnyIds([$this->dto(1)]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['imported']);
        $this->assertDatabaseCount('creatures', 2);
        $this->assertDatabaseHas('creatures', ['provider' => 'otherapi', 'name' => 'Other']);
    }

    #[Test]
    public function test_update_preserves_existing_image_when_payload_image_is_null(): void
    {
        $existing = Creature::factory()->create([
            'provider' => 'pokeapi', 'external_id' => 1, 'slug' => 'creature-1', 'image_ref' => self::IMAGE_A,
        ]);
        $this->provideForAnyIds([$this->dto(1, ['imageRef' => null])]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['updated']);
        $this->assertSame(self::IMAGE_A, $existing->fresh()->image_ref);
    }

    #[Test]
    public function test_update_replaces_image_when_payload_provides_one(): void
    {
        $existing = Creature::factory()->create([
            'provider' => 'pokeapi', 'external_id' => 1, 'slug' => 'creature-1', 'image_ref' => self::IMAGE_A,
        ]);
        $this->provideForAnyIds([$this->dto(1, ['imageRef' => self::IMAGE_B])]);

        $this->service->import([1]);

        $this->assertSame(self::IMAGE_B, $existing->fresh()->image_ref);
    }

    #[Test]
    public function test_update_backfills_image_when_existing_has_none(): void
    {
        $existing = Creature::factory()->create([
            'provider' => 'pokeapi', 'external_id' => 1, 'slug' => 'creature-1', 'image_ref' => null,
        ]);
        $this->provideForAnyIds([$this->dto(1, ['imageRef' => self::IMAGE_B])]);

        $this->service->import([1]);

        $this->assertSame(self::IMAGE_B, $existing->fresh()->image_ref);
    }

    #[Test]
    public function test_type_sync_removes_stale_types(): void
    {
        $existing = Creature::factory()->create(['provider' => 'pokeapi', 'external_id' => 1, 'slug' => 'creature-1']);
        $fire = CreatureType::factory()->create(['name' => 'Fire', 'slug' => 'fire']);
        $water = CreatureType::factory()->create(['name' => 'Water', 'slug' => 'water']);
        $existing->types()->attach($fire->id, ['slot' => 1]);
        $existing->types()->attach($water->id, ['slot' => 2]);

        $this->provideForAnyIds([$this->dto(1, [
            'types' => [['name' => 'Grass', 'slug' => 'grass', 'slot' => 1]],
        ])]);

        $this->service->import([1]);

        $types = $existing->fresh()->types;
        $this->assertSame(['grass'], $types->pluck('slug')->all());
        $this->assertDatabaseCount('creature_type', 1);
        // The detached types themselves remain in the catalog.
        $this->assertDatabaseHas('creature_types', ['slug' => 'fire']);
        $this->assertDatabaseHas('creature_types', ['slug' => 'water']);
    }

    #[Test]
    public function test_type_sync_updates_slot_of_retained_type(): void
    {
        $existing = Creature::factory()->create(['provider' => 'pokeapi', 'external_id' => 1, 'slug' => 'creature-1']);
        $grass = CreatureType::factory()->create(['name' => 'Grass', 'slug' => 'grass']);
        $existing->types()->attach($grass->id, ['slot' => 2]);

        $this->provideForAnyIds([$this->dto(1, [
            'types' => [
                ['name' => 'Poison', 'slug' => 'poison', 'slot' => 2],
                ['name' => 'Grass', 'slug' => 'grass', 'slot' => 1],
            ],
        ])]);

        // grass moves slot 2 -> 1 while poison takes slot 2: the unique(creature_id, slot)
        // index must not be violated by the sync ordering.
        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['updated'], implode(' | ', $summary['errors']));
        $this->assertSame(['grass', 'poison'], $existing->fresh()->types->pluck('slug')->all());
    }

    #[Test]
    public function test_repeated_import_is_idempotent(): void
    {
        $this->provideForAnyIds([$this->dto(1)]);

        $first = $this->service->import([1]);
        $second = $this->service->import([1]);

        $this->assertSame(1, $first['imported']);
        $this->assertSame(1, $second['updated']);
        $this->assertDatabaseCount('creatures', 1);
        $this->assertDatabaseCount('creature_types', 1);
        $this->assertDatabaseCount('creature_type', 1);
    }

    // ----------------------------------------------------------- ordering

    #[Test]
    public function test_out_of_order_payloads_are_processed_in_requested_id_order(): void
    {
        $this->provideForAnyIds([$this->dto(3), $this->dto(1), $this->dto(2)]);

        $summary = $this->service->import([1, 2, 3]);

        $this->assertSame(3, $summary['imported']);
        $this->assertSame(
            [1, 2, 3],
            Creature::query()->orderBy('id')->pluck('external_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    #[Test]
    public function test_unrequested_payloads_returned_by_provider_are_ignored(): void
    {
        $this->provideForAnyIds([$this->dto(1), $this->dto(999)]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['imported']);
        $this->assertDatabaseMissing('creatures', ['external_id' => 999]);
    }

    #[Test]
    public function test_duplicate_payloads_for_same_id_use_the_last_one(): void
    {
        $this->provideForAnyIds([
            $this->dto(1, ['name' => 'First']),
            $this->dto(1, ['name' => 'Last']),
        ]);

        $summary = $this->service->import([1]);

        $this->assertSame(1, $summary['imported']);
        $this->assertDatabaseHas('creatures', ['external_id' => 1, 'name' => 'Last']);
        $this->assertDatabaseMissing('creatures', ['name' => 'First']);
    }

    // ----------------------------------------------------------- evolutions

    #[Test]
    public function test_import_resolves_evolution_links_and_stages(): void
    {
        $this->provideForAnyIds([
            $this->dto(1, [
                'name' => 'Bulbasaur',
                'slug' => 'bulbasaur',
                'speciesExternalId' => 1,
                'evolvesFromSpeciesExternalId' => null,
            ]),
            $this->dto(2, [
                'name' => 'Ivysaur',
                'slug' => 'ivysaur',
                'speciesExternalId' => 2,
                'evolvesFromSpeciesExternalId' => 1,
            ]),
            $this->dto(3, [
                'name' => 'Venusaur',
                'slug' => 'venusaur',
                'speciesExternalId' => 3,
                'evolvesFromSpeciesExternalId' => 2,
            ]),
        ]);

        $summary = $this->service->import([1, 2, 3]);

        $this->assertSame(3, $summary['imported']);

        $base = Creature::query()->where('slug', 'bulbasaur')->firstOrFail();
        $mid = Creature::query()->where('slug', 'ivysaur')->firstOrFail();
        $final = Creature::query()->where('slug', 'venusaur')->firstOrFail();

        $this->assertNull($base->evolves_from_id);
        $this->assertSame(1, $base->evolution_stage);
        $this->assertSame($base->id, $mid->evolves_from_id);
        $this->assertSame(2, $mid->evolution_stage);
        $this->assertSame($mid->id, $final->evolves_from_id);
        $this->assertSame(3, $final->evolution_stage);
    }

    #[Test]
    public function test_import_species_ids_can_skip_evolution_resolve(): void
    {
        $this->provider->shouldReceive('fetchBySpeciesExternalIds')
            ->once()
            ->with([1])
            ->andReturn([
                $this->dto(1, [
                    'speciesExternalId' => 1,
                    'evolvesFromSpeciesExternalId' => null,
                ]),
            ]);

        $summary = $this->service->importSpeciesIds([1], resolveEvolutions: false);

        $this->assertSame(1, $summary['imported']);
        $this->assertDatabaseHas('creatures', [
            'external_id' => 1,
            'evolves_from_id' => null,
            'evolution_stage' => 1,
        ]);
    }
}
