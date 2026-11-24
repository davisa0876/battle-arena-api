<?php

namespace Tests\Feature\Application\Creatures;

use App\Application\Creatures\Services\CreatureCatalogService;
use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CreatureCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private CreatureCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CreatureCatalogService;
    }

    private function creature(string $name, ?string $slug = null): Creature
    {
        return Creature::factory()->create([
            'name' => $name,
            'slug' => $slug ?? str($name)->slug()->toString().'-'.uniqid(),
        ]);
    }

    private function type(string $name, ?string $slug = null): CreatureType
    {
        return CreatureType::factory()->create([
            'name' => $name,
            'slug' => $slug ?? str($name)->slug()->toString(),
        ]);
    }

    private function bulkCreate(int $count): void
    {
        Creature::factory()
            ->count($count)
            ->sequence(fn ($sequence) => [
                'name' => sprintf('Creature %03d', $sequence->index),
                'slug' => 'creature-'.$sequence->index,
            ])
            ->create();
    }

    /**
     * The page number is read from the current request (not from the filters array),
     * mirroring how the controller drives pagination.
     *
     * @param  array<string, mixed>  $filters
     */
    private function paginateAt(array $filters, int $page): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        request()->merge(['page' => $page]);

        return $this->service->paginate($filters);
    }

    /**
     * @return list<string>
     */
    private function names(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return collect($paginator->items())->pluck('name')->all();
    }

    // ---------------------------------------------------------------- paginate

    #[Test]
    public function test_default_per_page_is_twelve(): void
    {
        $this->bulkCreate(15);

        $page = $this->service->paginate();

        $this->assertSame(12, $page->perPage());
        $this->assertCount(12, $page->items());
        $this->assertSame(15, $page->total());
        $this->assertSame(2, $page->lastPage());
    }

    #[Test]
    public function test_null_per_page_uses_default(): void
    {
        $this->bulkCreate(13);

        $page = $this->service->paginate(['per_page' => null]);

        $this->assertSame(CreatureCatalogService::DEFAULT_PER_PAGE, $page->perPage());
    }

    #[Test]
    public function test_per_page_is_clamped_to_maximum_of_fifty(): void
    {
        $this->bulkCreate(55);

        $page = $this->service->paginate(['per_page' => 500]);

        $this->assertSame(50, $page->perPage());
        $this->assertCount(50, $page->items());
    }

    #[Test]
    public function test_per_page_of_exactly_fifty_is_honoured(): void
    {
        $this->bulkCreate(51);

        $page = $this->service->paginate(['per_page' => 50]);

        $this->assertSame(50, $page->perPage());
        $this->assertSame(2, $page->lastPage());
    }

    /**
     * @return array<string, array{int|string}>
     */
    public static function belowMinimumPerPage(): array
    {
        return [
            'zero' => [0],
            'negative' => [-10],
        ];
    }

    #[Test]
    #[DataProvider('belowMinimumPerPage')]
    public function test_per_page_is_clamped_to_minimum_of_one(int|string $perPage): void
    {
        $this->bulkCreate(3);

        $page = $this->service->paginate(['per_page' => $perPage]);

        $this->assertSame(1, $page->perPage());
        $this->assertCount(1, $page->items());
        $this->assertSame(3, $page->total());
    }

    #[Test]
    public function test_numeric_string_per_page_is_cast(): void
    {
        $this->bulkCreate(5);

        $page = $this->service->paginate(['per_page' => '2']);

        $this->assertSame(2, $page->perPage());
    }

    #[Test]
    public function test_orders_by_name_then_id(): void
    {
        $zed = $this->creature('Zed');
        $twinFirst = $this->creature('Twin');
        $alpha = $this->creature('Alpha');
        $twinSecond = $this->creature('Twin');

        $ids = collect($this->service->paginate()->items())->pluck('id')->all();

        $this->assertSame([$alpha->id, $twinFirst->id, $twinSecond->id, $zed->id], $ids);
    }

    #[Test]
    public function test_ordering_is_stable_across_pages(): void
    {
        $this->bulkCreate(5);

        $first = $this->paginateAt(['per_page' => 2], 1);
        $second = $this->paginateAt(['per_page' => 2], 2);
        $third = $this->paginateAt(['per_page' => 2], 3);

        $this->assertSame(['Creature 000', 'Creature 001'], $this->names($first));
        $this->assertSame(['Creature 002', 'Creature 003'], $this->names($second));
        $this->assertSame(['Creature 004'], $this->names($third));
    }

    #[Test]
    public function test_eager_loads_types_ordered_by_slot(): void
    {
        $creature = $this->creature('Dual');
        $fire = $this->type('Fire');
        $flying = $this->type('Flying');
        $creature->types()->attach($flying->id, ['slot' => 2]);
        $creature->types()->attach($fire->id, ['slot' => 1]);

        $item = $this->service->paginate()->items()[0];

        $this->assertTrue($item->relationLoaded('types'));
        $this->assertSame(['fire', 'flying'], $item->types->pluck('slug')->all());
    }

    #[Test]
    public function test_empty_catalog_returns_empty_page(): void
    {
        $page = $this->service->paginate();

        $this->assertSame(0, $page->total());
        $this->assertSame([], $page->items());
    }

    // ------------------------------------------------------------------ search

    #[Test]
    public function test_search_matches_substring(): void
    {
        $this->creature('Flame Drake');
        $this->creature('Aqua Sprite');

        $this->assertSame(['Flame Drake'], $this->names($this->service->paginate(['search' => 'ame D'])));
    }

    #[Test]
    public function test_search_is_trimmed(): void
    {
        $this->creature('Flame Drake');
        $this->creature('Aqua Sprite');

        $this->assertSame(['Flame Drake'], $this->names($this->service->paginate(['search' => "  flame\t "])));
    }

    #[Test]
    public function test_search_is_case_insensitive(): void
    {
        $this->creature('Flame Drake');
        $this->creature('Aqua Sprite');

        $this->assertSame(['Flame Drake'], $this->names($this->service->paginate(['search' => 'FLAME'])));
        $this->assertSame(['Aqua Sprite'], $this->names($this->service->paginate(['search' => 'aQuA'])));
    }

    #[Test]
    public function test_blank_search_applies_no_filter(): void
    {
        $this->creature('Flame Drake');
        $this->creature('Aqua Sprite');

        $this->assertSame(2, $this->service->paginate(['search' => '   '])->total());
        $this->assertSame(2, $this->service->paginate(['search' => null])->total());
        $this->assertSame(2, $this->service->paginate(['search' => ''])->total());
    }

    #[Test]
    public function test_search_without_match_returns_empty_page(): void
    {
        $this->creature('Flame Drake');

        $this->assertSame(0, $this->service->paginate(['search' => 'zzz'])->total());
    }

    #[Test]
    public function test_search_does_not_match_slug_only(): void
    {
        $this->creature('Flame Drake', 'secret-slug');

        $this->assertSame(0, $this->service->paginate(['search' => 'secret'])->total());
    }

    #[Test]
    public function test_literal_percent_in_search_is_escaped(): void
    {
        $this->creature('100% Pure');
        $this->creature('100 Percent');
        $this->creature('Plain Name');

        $this->assertSame(['100% Pure'], $this->names($this->service->paginate(['search' => '100%'])));
    }

    #[Test]
    public function test_lone_percent_is_not_treated_as_wildcard(): void
    {
        $this->creature('100% Pure');
        $this->creature('Plain Name');
        $this->creature('Other');

        $this->assertSame(['100% Pure'], $this->names($this->service->paginate(['search' => '%'])));
    }

    #[Test]
    public function test_literal_underscore_in_search_is_escaped(): void
    {
        $this->creature('Snake_Eye');
        $this->creature('SnakeXEye');
        $this->creature('Snake Eye');

        $this->assertSame(['Snake_Eye'], $this->names($this->service->paginate(['search' => 'snake_eye'])));
    }

    #[Test]
    public function test_lone_underscore_is_not_treated_as_single_char_wildcard(): void
    {
        $this->creature('Snake_Eye');
        $this->creature('Plain');

        $this->assertSame(['Snake_Eye'], $this->names($this->service->paginate(['search' => '_'])));
    }

    #[Test]
    public function test_literal_backslash_in_search_is_escaped(): void
    {
        $this->creature('Back\\Slash');
        $this->creature('Back Slash');

        $this->assertSame(['Back\\Slash'], $this->names($this->service->paginate(['search' => 'back\\'])));
    }

    // -------------------------------------------------------------------- type

    #[Test]
    public function test_filters_by_type_slug(): void
    {
        $fire = $this->type('Fire');
        $water = $this->type('Water');
        $this->creature('Ember Fox')->types()->attach($fire->id, ['slot' => 1]);
        $this->creature('Tide Pup')->types()->attach($water->id, ['slot' => 1]);
        $this->creature('Plain Rock');

        $this->assertSame(['Ember Fox'], $this->names($this->service->paginate(['type' => 'fire'])));
    }

    #[Test]
    public function test_type_filter_matches_secondary_slot(): void
    {
        $fire = $this->type('Fire');
        $flying = $this->type('Flying');
        $dragon = $this->creature('Fire Wyrm');
        $dragon->types()->attach($fire->id, ['slot' => 1]);
        $dragon->types()->attach($flying->id, ['slot' => 2]);

        $page = $this->service->paginate(['type' => 'flying']);

        $this->assertSame(['Fire Wyrm'], $this->names($page));
        // Filtering does not hide the creature's other types.
        $this->assertSame(['fire', 'flying'], $page->items()[0]->types->pluck('slug')->all());
    }

    #[Test]
    public function test_type_filter_is_trimmed(): void
    {
        $fire = $this->type('Fire');
        $this->creature('Ember Fox')->types()->attach($fire->id, ['slot' => 1]);

        $this->assertSame(1, $this->service->paginate(['type' => '  fire '])->total());
    }

    #[Test]
    public function test_blank_type_applies_no_filter(): void
    {
        $this->creature('One');
        $this->creature('Two');

        $this->assertSame(2, $this->service->paginate(['type' => ''])->total());
        $this->assertSame(2, $this->service->paginate(['type' => null])->total());
    }

    #[Test]
    public function test_unknown_type_returns_empty_page(): void
    {
        $fire = $this->type('Fire');
        $this->creature('Ember Fox')->types()->attach($fire->id, ['slot' => 1]);

        $this->assertSame(0, $this->service->paginate(['type' => 'ghost'])->total());
    }

    #[Test]
    public function test_type_filter_does_not_duplicate_creatures_with_matching_type(): void
    {
        $fire = $this->type('Fire');
        $this->creature('Ember Fox')->types()->attach($fire->id, ['slot' => 1]);

        $page = $this->service->paginate(['type' => 'fire']);

        $this->assertSame(1, $page->total());
        $this->assertCount(1, $page->items());
    }

    #[Test]
    public function test_combined_search_and_type_filters_are_anded(): void
    {
        $fire = $this->type('Fire');
        $water = $this->type('Water');

        $this->creature('Flame Drake')->types()->attach($fire->id, ['slot' => 1]);
        $this->creature('Flame Eel')->types()->attach($water->id, ['slot' => 1]);
        $this->creature('Ember Fox')->types()->attach($fire->id, ['slot' => 1]);

        $names = $this->names($this->service->paginate(['search' => 'flame', 'type' => 'fire']));

        $this->assertSame(['Flame Drake'], $names);
    }

    #[Test]
    public function test_combined_filters_paginate_within_filtered_results(): void
    {
        $fire = $this->type('Fire');
        foreach (['Flame A', 'Flame B', 'Flame C'] as $name) {
            $this->creature($name)->types()->attach($fire->id, ['slot' => 1]);
        }
        $this->creature('Other');

        $page = $this->paginateAt(['search' => 'flame', 'type' => 'fire', 'per_page' => 2], 2);

        $this->assertSame(3, $page->total());
        $this->assertSame(['Flame C'], $this->names($page));
    }

    // ------------------------------------------------------------- findBySlug

    #[Test]
    public function test_find_by_slug_returns_creature_with_types_ordered_by_slot(): void
    {
        $creature = $this->creature('Dual', 'dual');
        $poison = $this->type('Poison');
        $grass = $this->type('Grass');
        // Attach in reverse slot order to prove ordering is by slot, not insertion.
        $creature->types()->attach($poison->id, ['slot' => 2]);
        $creature->types()->attach($grass->id, ['slot' => 1]);

        $found = $this->service->findBySlug('dual');

        $this->assertNotNull($found);
        $this->assertTrue($found->is($creature));
        $this->assertTrue($found->relationLoaded('types'));
        $this->assertSame(['grass', 'poison'], $found->types->pluck('slug')->all());
        $this->assertSame([1, 2], $found->types->pluck('pivot.slot')->all());
    }

    #[Test]
    public function test_find_by_slug_returns_null_when_missing(): void
    {
        $this->creature('Dual', 'dual');

        $this->assertNull($this->service->findBySlug('nope'));
    }

    #[Test]
    public function test_find_by_slug_is_exact_match(): void
    {
        $this->creature('Dual', 'dual-form');

        $this->assertNull($this->service->findBySlug('dual'));
        $this->assertNull($this->service->findBySlug('dual-form-extra'));
    }

    #[Test]
    public function test_find_by_slug_returns_creature_without_types_as_empty_collection(): void
    {
        $this->creature('Typeless', 'typeless');

        $found = $this->service->findBySlug('typeless');

        $this->assertNotNull($found);
        $this->assertTrue($found->relationLoaded('types'));
        $this->assertCount(0, $found->types);
    }

    // --------------------------------------------------------------- listTypes

    #[Test]
    public function test_list_types_is_alphabetical_by_name(): void
    {
        $this->type('Water');
        $this->type('Fire');
        $this->type('Grass');

        $this->assertSame(['fire', 'grass', 'water'], $this->service->listTypes()->pluck('slug')->all());
    }

    #[Test]
    public function test_list_types_breaks_name_ties_by_id(): void
    {
        $second = $this->type('Dup', 'dup-b');
        $first = $this->type('Dup', 'dup-a');
        $this->type('Alpha');

        $ids = $this->service->listTypes()->pluck('id')->all();

        $this->assertSame([
            CreatureType::query()->where('slug', 'alpha')->value('id'),
            $second->id,
            $first->id,
        ], $ids);
    }

    #[Test]
    public function test_list_types_returns_empty_collection_when_none_exist(): void
    {
        $this->assertTrue($this->service->listTypes()->isEmpty());
    }
}
