<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ValidationLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_french_accept_language_localizes_invalid_per_page_message(): void
    {
        $this->withHeaders(['Accept-Language' => 'fr'])
            ->getJson('/api/creatures?per_page=abc')
            ->assertUnprocessable()
            ->assertJsonPath('errors.per_page.0', 'Le champ éléments par page doit être un entier.')
            ->assertHeader('Content-Language', 'fr');
    }

    public function test_brazilian_portuguese_accept_language_localizes_invalid_per_page_message(): void
    {
        $this->withHeaders(['Accept-Language' => 'pt-BR'])
            ->getJson('/api/creatures?per_page=abc')
            ->assertUnprocessable()
            ->assertJsonPath('errors.per_page.0', 'O campo itens por página deve conter um número inteiro.')
            ->assertHeader('Content-Language', 'pt-BR');
    }

    public function test_english_remains_the_default_for_invalid_per_page(): void
    {
        $this->getJson('/api/creatures?per_page=abc')
            ->assertUnprocessable()
            ->assertJsonPath('errors.per_page.0', 'The items per page field must be an integer.');
    }

    public function test_out_of_range_per_page_is_localized_in_spanish(): void
    {
        $message = $this->withHeaders(['Accept-Language' => 'es'])
            ->getJson('/api/creatures?per_page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page'])
            ->json('errors.per_page.0');

        $this->assertStringContainsString('elementos por página', $message);
    }

    #[DataProvider('supportedLocales')]
    public function test_every_locale_ships_the_full_validation_structure(string $locale): void
    {
        $english = require lang_path('en/validation.php');
        $translated = require lang_path("{$locale}/validation.php");

        $this->assertSame(
            $this->keyTree($english),
            $this->keyTree($translated),
            "lang/{$locale}/validation.php key tree differs from lang/en/validation.php",
        );

        foreach (['search', 'type', 'page', 'per_page'] as $attribute) {
            $this->assertNotEmpty($translated['attributes'][$attribute] ?? null, "{$locale} missing attribute {$attribute}");
        }

        $this->assertTrue(File::exists(lang_path("{$locale}/api.php")));
        $this->assertTrue(File::exists(lang_path("{$locale}/creatures.php")));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function supportedLocales(): array
    {
        return [
            'en' => ['en'],
            'fr' => ['fr'],
            'pt_BR' => ['pt_BR'],
            'it' => ['it'],
            'es' => ['es'],
        ];
    }

    /**
     * Flattens a nested language array into sorted dot keys, ignoring free-form `custom` and `attributes` entries.
     *
     * @param  array<string, mixed>  $lines
     * @return list<string>
     */
    private function keyTree(array $lines, string $prefix = ''): array
    {
        $keys = [];

        foreach ($lines as $key => $value) {
            if ($prefix === '' && in_array($key, ['custom', 'attributes'], true)) {
                continue;
            }

            if (is_array($value)) {
                $keys = [...$keys, ...$this->keyTree($value, $prefix.$key.'.')];
            } else {
                $keys[] = $prefix.$key;
            }
        }

        sort($keys);

        return $keys;
    }
}
