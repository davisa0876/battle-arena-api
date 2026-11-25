<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LangParityTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function files(): array
    {
        return [
            'api' => ['api'],
            'auth' => ['auth'],
            'passwords' => ['passwords'],
        ];
    }

    #[DataProvider('files')]
    public function test_every_locale_has_the_same_keys_as_english(string $file): void
    {
        $english = $this->flatten(require lang_path("en/{$file}.php"));

        foreach (['fr', 'pt_BR', 'it', 'es'] as $locale) {
            $translated = $this->flatten(require lang_path("{$locale}/{$file}.php"));

            $this->assertSame(array_keys($english), array_keys($translated), "lang/{$locale}/{$file}.php keys differ from en");
            foreach ($translated as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "{$locale}.{$file}.{$key} is empty");
                $this->assertSame(
                    $this->placeholders($english[$key]),
                    $this->placeholders((string) $value),
                    "{$locale}.{$file}.{$key} placeholders differ",
                );
            }
        }
    }

    public function test_notification_json_translations_share_the_same_keys(): void
    {
        $reference = array_keys($this->loadJson('fr'));
        $this->assertNotEmpty($reference);

        foreach (['pt_BR', 'it', 'es'] as $locale) {
            $this->assertSame($reference, array_keys($this->loadJson($locale)), "lang/{$locale}.json keys differ");
        }
    }

    public function test_translated_mail_lines_are_used_for_reset_emails(): void
    {
        $this->app->setLocale('fr');

        $this->assertSame('Réinitialiser le mot de passe', __('Reset Password'));
    }

    /**
     * @return array<string, mixed>
     */
    private function loadJson(string $locale): array
    {
        return json_decode((string) file_get_contents(lang_path("{$locale}.json")), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $lines
     * @return array<string, mixed>
     */
    private function flatten(array $lines, string $prefix = ''): array
    {
        $flat = [];
        foreach ($lines as $key => $value) {
            is_array($value)
                ? $flat += $this->flatten($value, $prefix.$key.'.')
                : $flat[$prefix.$key] = $value;
        }
        ksort($flat);

        return $flat;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $line): array
    {
        preg_match_all('/:[a-z_]+/i', $line, $matches);
        sort($matches[0]);

        return $matches[0];
    }
}
