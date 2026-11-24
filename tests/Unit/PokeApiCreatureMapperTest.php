<?php

namespace Tests\Unit;

use App\Infrastructure\External\PokeApi\PokeApiCreatureMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PokeApiCreatureMapperTest extends TestCase
{
    private const ARTWORK = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/1.png';

    private const HOME = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/home/1.png';

    private const DEFAULT_SPRITE = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/1.png';

    /**
     * Minimal valid PokéAPI payload, overridable per test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'id' => 7,
            'name' => 'squirtle',
            'height' => 5,
            'weight' => 90,
            'stats' => [
                ['base_stat' => 44, 'stat' => ['name' => 'hp']],
                ['base_stat' => 48, 'stat' => ['name' => 'attack']],
                ['base_stat' => 65, 'stat' => ['name' => 'defense']],
                ['base_stat' => 50, 'stat' => ['name' => 'special-attack']],
                ['base_stat' => 64, 'stat' => ['name' => 'special-defense']],
                ['base_stat' => 43, 'stat' => ['name' => 'speed']],
            ],
            'types' => [
                ['slot' => 1, 'type' => ['name' => 'water']],
            ],
            'sprites' => [
                'front_default' => null,
                'other' => [
                    'official-artwork' => ['front_default' => null],
                    'home' => ['front_default' => null],
                ],
            ],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function bulbasaurFixture(): array
    {
        return json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pokeapi/pokemon_1.json')),
            true,
        );
    }

    /**
     * @param  array<string, mixed>  $sprites
     * @return array<string, mixed>
     */
    private function payloadWithSprites(array $sprites): array
    {
        return $this->payload(['sprites' => $sprites]);
    }

    #[Test]
    public function test_maps_provider_payload_into_domain_dto(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->bulbasaurFixture());

        $this->assertTrue($dto->isComplete());
        $this->assertSame('pokeapi', $dto->provider);
        $this->assertSame(1, $dto->externalId);
        $this->assertSame('Bulbasaur', $dto->name);
        $this->assertSame('bulbasaur', $dto->slug);
        $this->assertSame(70, $dto->heightCm);
        $this->assertSame(6900, $dto->weightG);
        $this->assertSame(65, $dto->baseSpecialAttack);
        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/1.png',
            $dto->imageRef,
        );
        $this->assertCount(2, $dto->types);
    }

    #[Test]
    public function test_incomplete_payload_is_detected(): void
    {
        $payload = json_decode(
            (string) file_get_contents(base_path('tests/Fixtures/pokeapi/pokemon_incomplete.json')),
            true,
        );

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertFalse($dto->isComplete());
        $this->assertNull($dto->imageRef);
    }

    #[Test]
    public function test_rejects_untrusted_image_hosts(): void
    {
        $payload = $this->bulbasaurFixture();
        $payload['sprites']['other']['official-artwork']['front_default'] = 'https://evil.example/sprite.png';
        $payload['sprites']['other']['home']['front_default'] = null;
        $payload['sprites']['front_default'] = 'http://insecure.local/1.png';

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertNull($dto->imageRef);
    }

    #[Test]
    public function test_falls_back_to_standard_sprite(): void
    {
        $payload = $this->bulbasaurFixture();
        $payload['sprites']['other']['official-artwork']['front_default'] = null;
        $payload['sprites']['other']['home']['front_default'] = null;

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame(
            'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/1.png',
            $dto->imageRef,
        );
    }

    #[Test]
    public function test_maps_all_six_base_stats_to_the_right_properties(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload());

        $this->assertSame(44, $dto->baseHp);
        $this->assertSame(48, $dto->baseAttack);
        $this->assertSame(65, $dto->baseDefense);
        $this->assertSame(50, $dto->baseSpecialAttack);
        $this->assertSame(64, $dto->baseSpecialDefense);
        $this->assertSame(43, $dto->baseSpeed);
        $this->assertTrue($dto->isComplete());
    }

    #[Test]
    public function test_stat_order_in_payload_does_not_matter(): void
    {
        $payload = $this->payload();
        $payload['stats'] = array_reverse($payload['stats']);

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame(44, $dto->baseHp);
        $this->assertSame(43, $dto->baseSpeed);
    }

    #[Test]
    public function test_unknown_stats_are_ignored(): void
    {
        $payload = $this->payload();
        $payload['stats'][] = ['base_stat' => 999, 'stat' => ['name' => 'accuracy']];
        $payload['stats'][] = ['base_stat' => 888, 'stat' => ['name' => 'evasion']];

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame(44, $dto->baseHp);
        $this->assertSame(43, $dto->baseSpeed);
        $this->assertTrue($dto->isComplete());
    }

    #[Test]
    public function test_only_unknown_stats_leaves_creature_incomplete(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload([
            'stats' => [['base_stat' => 10, 'stat' => ['name' => 'accuracy']]],
        ]));

        $this->assertSame(0, $dto->baseHp);
        $this->assertFalse($dto->isComplete());
    }

    #[Test]
    public function test_non_array_stat_rows_are_skipped(): void
    {
        $payload = $this->payload();
        $payload['stats'][] = 'not-an-array';
        $payload['stats'][] = 42;
        $payload['stats'][] = null;

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame(44, $dto->baseHp);
        $this->assertTrue($dto->isComplete());
    }

    #[Test]
    public function test_stat_row_without_base_stat_maps_to_zero(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload([
            'stats' => [['stat' => ['name' => 'hp']]],
        ]));

        $this->assertSame(0, $dto->baseHp);
        $this->assertFalse($dto->isComplete());
    }

    #[Test]
    public function test_missing_stats_key_yields_zero_stats(): void
    {
        $payload = $this->payload();
        unset($payload['stats']);

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame(0, $dto->baseHp);
        $this->assertSame(0, $dto->baseSpeed);
        $this->assertFalse($dto->isComplete());
    }

    #[Test]
    public function test_types_are_sorted_by_slot(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload([
            'types' => [
                ['slot' => 2, 'type' => ['name' => 'poison']],
                ['slot' => 1, 'type' => ['name' => 'grass']],
            ],
        ]));

        $this->assertSame(
            [
                ['name' => 'Grass', 'slug' => 'grass', 'slot' => 1],
                ['name' => 'Poison', 'slug' => 'poison', 'slot' => 2],
            ],
            $dto->types,
        );
    }

    #[Test]
    public function test_hyphenated_type_names_are_title_cased_with_slug_preserved(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload([
            'types' => [['slot' => 1, 'type' => ['name' => 'super-rare-type']]],
        ]));

        $this->assertSame('Super Rare Type', $dto->types[0]['name']);
        $this->assertSame('super-rare-type', $dto->types[0]['slug']);
    }

    #[Test]
    public function test_non_array_and_nameless_type_rows_are_skipped(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload([
            'types' => [
                'oops',
                7,
                ['slot' => 3, 'type' => ['name' => '']],
                ['slot' => 4],
                ['slot' => 1, 'type' => ['name' => 'water']],
            ],
        ]));

        $this->assertCount(1, $dto->types);
        $this->assertSame('water', $dto->types[0]['slug']);
    }

    #[Test]
    public function test_type_row_without_slot_gets_slot_zero_and_is_incomplete(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload([
            'types' => [['type' => ['name' => 'water']]],
        ]));

        $this->assertSame(0, $dto->types[0]['slot']);
        $this->assertFalse($dto->isComplete());
    }

    #[Test]
    public function test_missing_types_key_yields_empty_types(): void
    {
        $payload = $this->payload();
        unset($payload['types']);

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertSame([], $dto->types);
        $this->assertFalse($dto->isComplete());
    }

    #[Test]
    public function test_height_decimetres_convert_to_centimetres(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['height' => 17]));

        $this->assertSame(170, $dto->heightCm);
    }

    #[Test]
    public function test_weight_hectograms_convert_to_grams(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['weight' => 905]));

        $this->assertSame(90500, $dto->weightG);
    }

    #[Test]
    public function test_missing_height_and_weight_map_to_null(): void
    {
        $payload = $this->payload();
        unset($payload['height'], $payload['weight']);

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertNull($dto->heightCm);
        $this->assertNull($dto->weightG);
        $this->assertTrue($dto->isComplete());
    }

    #[Test]
    public function test_explicit_zero_height_and_weight_are_preserved_as_zero(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['height' => 0, 'weight' => 0]));

        $this->assertSame(0, $dto->heightCm);
        $this->assertSame(0, $dto->weightG);
    }

    #[Test]
    public function test_official_artwork_wins_over_home_and_default(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => self::DEFAULT_SPRITE,
            'other' => [
                'official-artwork' => ['front_default' => self::ARTWORK],
                'home' => ['front_default' => self::HOME],
            ],
        ]));

        $this->assertSame(self::ARTWORK, $dto->imageRef);
    }

    #[Test]
    public function test_home_artwork_wins_over_default_when_official_missing(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => self::DEFAULT_SPRITE,
            'other' => [
                'official-artwork' => ['front_default' => null],
                'home' => ['front_default' => self::HOME],
            ],
        ]));

        $this->assertSame(self::HOME, $dto->imageRef);
    }

    #[Test]
    public function test_default_sprite_used_when_other_artwork_missing(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => self::DEFAULT_SPRITE,
        ]));

        $this->assertSame(self::DEFAULT_SPRITE, $dto->imageRef);
    }

    #[Test]
    public function test_untrusted_higher_priority_candidate_falls_through_to_next_trusted(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => self::DEFAULT_SPRITE,
            'other' => [
                'official-artwork' => ['front_default' => 'https://evil.example/a.png'],
                'home' => ['front_default' => self::HOME],
            ],
        ]));

        $this->assertSame(self::HOME, $dto->imageRef);
    }

    #[Test]
    public function test_no_sprites_yields_null_image_without_inventing_a_url(): void
    {
        $payload = $this->payload();
        unset($payload['sprites']);

        $dto = (new PokeApiCreatureMapper)->map($payload);

        $this->assertNull($dto->imageRef);
    }

    #[Test]
    public function test_non_string_sprite_candidates_are_ignored(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => self::DEFAULT_SPRITE,
            'other' => [
                'official-artwork' => ['front_default' => ['nested' => 'array']],
                'home' => ['front_default' => 123],
            ],
        ]));

        $this->assertSame(self::DEFAULT_SPRITE, $dto->imageRef);
    }

    #[Test]
    public function test_image_url_is_trimmed(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => '  '.self::DEFAULT_SPRITE.'  ',
        ]));

        $this->assertSame(self::DEFAULT_SPRITE, $dto->imageRef);
    }

    #[Test]
    public function test_blank_image_url_is_ignored(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites([
            'front_default' => '   ',
        ]));

        $this->assertNull($dto->imageRef);
    }

    #[Test]
    public function test_trusted_host_match_is_case_insensitive(): void
    {
        $url = 'https://RAW.GithubUserContent.com/PokeAPI/sprites/master/sprites/pokemon/1.png';

        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites(['front_default' => $url]));

        $this->assertSame($url, $dto->imageRef);
    }

    #[Test]
    public function test_accepts_subdomain_of_trusted_host(): void
    {
        $url = 'https://cdn.raw.githubusercontent.com/PokeAPI/sprites/1.png';

        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites(['front_default' => $url]));

        $this->assertSame($url, $dto->imageRef);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function rejectedImageUrls(): array
    {
        return [
            'http scheme' => ['http://raw.githubusercontent.com/PokeAPI/sprites/1.png'],
            'ftp scheme' => ['ftp://raw.githubusercontent.com/1.png'],
            'scheme-relative' => ['//raw.githubusercontent.com/1.png'],
            'empty host' => ['https:///PokeAPI/sprites/1.png'],
            'no scheme or host' => ['/PokeAPI/sprites/1.png'],
            'userinfo spoof' => ['https://raw.githubusercontent.com@evil.example/1.png'],
            'userinfo with password spoof' => ['https://raw.githubusercontent.com:secret@evil.example/1.png'],
            'trusted host in path' => ['https://evil.example/raw.githubusercontent.com/1.png'],
            'trusted host in query' => ['https://evil.example/1.png?u=raw.githubusercontent.com'],
            'suffix lookalike' => ['https://notraw.githubusercontent.com/1.png'],
            'prefix lookalike' => ['https://raw.githubusercontent.com.evil.example/1.png'],
            'unrelated host' => ['https://example.com/1.png'],
            'other github host' => ['https://github.com/PokeAPI/sprites/1.png'],
            'pokeapi host' => ['https://pokeapi.co/1.png'],
            'garbage' => ['not a url at all'],
        ];
    }

    #[Test]
    #[DataProvider('rejectedImageUrls')]
    public function test_rejects_untrusted_or_malformed_image_urls(string $url): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payloadWithSprites(['front_default' => $url]));

        $this->assertNull($dto->imageRef);
    }

    #[Test]
    public function test_name_is_title_cased_and_slug_preserves_hyphens(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['name' => 'mr-mime']));

        $this->assertSame('Mr Mime', $dto->name);
        $this->assertSame('mr-mime', $dto->slug);
    }

    #[Test]
    public function test_multi_hyphen_name_is_title_cased(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['name' => 'tapu-koko-special']));

        $this->assertSame('Tapu Koko Special', $dto->name);
        $this->assertSame('tapu-koko-special', $dto->slug);
    }

    #[Test]
    public function test_single_word_name_is_capitalised(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['name' => 'pikachu']));

        $this->assertSame('Pikachu', $dto->name);
        $this->assertSame('pikachu', $dto->slug);
    }

    #[Test]
    public function test_missing_identity_fields_make_dto_incomplete(): void
    {
        $dto = (new PokeApiCreatureMapper)->map(['stats' => [], 'types' => []]);

        $this->assertSame(0, $dto->externalId);
        $this->assertSame('', $dto->name);
        $this->assertSame('', $dto->slug);
        $this->assertFalse($dto->isComplete());
    }

    #[Test]
    public function test_provider_is_always_pokeapi(): void
    {
        $this->assertSame('pokeapi', (new PokeApiCreatureMapper)->map($this->payload())->provider);
    }

    #[Test]
    public function test_external_id_is_cast_to_int(): void
    {
        $dto = (new PokeApiCreatureMapper)->map($this->payload(['id' => '25']));

        $this->assertSame(25, $dto->externalId);
    }
}
