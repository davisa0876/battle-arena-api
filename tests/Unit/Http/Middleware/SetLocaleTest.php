<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SetLocale;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    /**
     * Runs the middleware and returns the response plus the locale seen by the "controller".
     *
     * @return array{0: Response, 1: string}
     */
    private function dispatch(?string $acceptLanguage, ?string $existingVary = null): array
    {
        $headers = $acceptLanguage === null ? [] : ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage];
        $request = Request::create('/api/creatures', 'GET', server: $headers);

        $localeSeenDownstream = '';
        $response = (new SetLocale)->handle($request, function () use (&$localeSeenDownstream, $existingVary): Response {
            $localeSeenDownstream = app()->getLocale();
            $response = new Response('ok');
            if ($existingVary !== null) {
                $response->headers->set('Vary', $existingVary);
            }

            return $response;
        });

        return [$response, $localeSeenDownstream];
    }

    #[Test]
    public function test_missing_accept_language_defaults_to_english(): void
    {
        [$response, $downstream] = $this->dispatch(null);

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', $downstream);
        $this->assertSame('en', $response->headers->get('Content-Language'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blankHeaders(): array
    {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'only commas' => [' , , '],
        ];
    }

    #[Test]
    #[DataProvider('blankHeaders')]
    public function test_blank_accept_language_defaults_to_english(string $header): void
    {
        [$response] = $this->dispatch($header);

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_higher_quality_value_wins_regardless_of_order(): void
    {
        [$response] = $this->dispatch('fr;q=0.8, es;q=0.9');

        $this->assertSame('es', app()->getLocale());
        $this->assertSame('es', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_implicit_quality_one_beats_explicit_lower_quality(): void
    {
        [$response] = $this->dispatch('it;q=0.5, fr');

        $this->assertSame('fr', app()->getLocale());
        $this->assertSame('fr', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_equal_quality_preserves_header_order(): void
    {
        [$response] = $this->dispatch('es, fr');

        $this->assertSame('es', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_unsupported_highest_quality_falls_through_to_supported_candidate(): void
    {
        [$response] = $this->dispatch('de, ja;q=0.9, it;q=0.5');

        $this->assertSame('it', app()->getLocale());
        $this->assertSame('it', $response->headers->get('Content-Language'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function portugueseVariants(): array
    {
        return [
            'bare pt' => ['pt', 'pt_BR', 'pt-BR'],
            'pt-BR' => ['pt-BR', 'pt_BR', 'pt-BR'],
            'pt_BR underscore' => ['pt_BR', 'pt_BR', 'pt-BR'],
            'pt-br lowercase' => ['pt-br', 'pt_BR', 'pt-BR'],
            'PT-BR uppercase' => ['PT-BR', 'pt_BR', 'pt-BR'],
            'pt-PT maps to pt_BR' => ['pt-PT', 'pt_BR', 'pt-BR'],
        ];
    }

    #[Test]
    #[DataProvider('portugueseVariants')]
    public function test_portuguese_variants_map_to_brazilian_portuguese(string $header, string $internal, string $external): void
    {
        [$response, $downstream] = $this->dispatch($header);

        $this->assertSame($internal, app()->getLocale());
        $this->assertSame($internal, $downstream);
        $this->assertSame($external, $response->headers->get('Content-Language'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function regionalVariants(): array
    {
        return [
            'fr-CA' => ['fr-CA', 'fr'],
            'fr-FR' => ['fr-FR', 'fr'],
            'es-MX' => ['es-MX', 'es'],
            'it-CH' => ['it-CH', 'it'],
            'en-GB' => ['en-GB', 'en'],
            'en-US with q' => ['en-US,en;q=0.9', 'en'],
        ];
    }

    #[Test]
    #[DataProvider('regionalVariants')]
    public function test_regional_variants_fall_back_to_primary_language(string $header, string $expected): void
    {
        [$response] = $this->dispatch($header);

        $this->assertSame($expected, app()->getLocale());
        $this->assertSame($expected, $response->headers->get('Content-Language'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsupportedHeaders(): array
    {
        return [
            'german' => ['de-DE,de;q=0.9'],
            'japanese' => ['ja'],
            'wildcard' => ['*'],
            'garbage' => ['!!!;;;q=abc'],
        ];
    }

    #[Test]
    #[DataProvider('unsupportedHeaders')]
    public function test_unsupported_languages_fall_back_to_english(string $header): void
    {
        [$response] = $this->dispatch($header);

        $this->assertSame('en', app()->getLocale());
        $this->assertSame('en', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_matching_is_case_insensitive(): void
    {
        [$response] = $this->dispatch('FR');

        $this->assertSame('fr', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_whitespace_around_tags_and_parameters_is_tolerated(): void
    {
        [$response] = $this->dispatch('  de ,  es ; q=0.7 ');

        $this->assertSame('es', $response->headers->get('Content-Language'));
    }

    #[Test]
    public function test_vary_header_is_set_when_response_has_none(): void
    {
        [$response] = $this->dispatch('fr');

        $this->assertSame('Accept-Language', $response->headers->get('Vary'));
    }

    #[Test]
    public function test_vary_is_merged_with_existing_values(): void
    {
        [$response] = $this->dispatch('fr', 'Origin');

        $this->assertSame('Origin, Accept-Language', $response->headers->get('Vary'));
    }

    #[Test]
    public function test_vary_is_not_duplicated_when_accept_language_already_present(): void
    {
        [$response] = $this->dispatch('fr', 'Accept-Language');

        $this->assertSame('Accept-Language', $response->headers->get('Vary'));
    }

    #[Test]
    public function test_vary_keeps_existing_list_without_duplicating_accept_language(): void
    {
        [$response] = $this->dispatch('fr', 'Origin, Accept-Language, Accept-Encoding');

        $this->assertSame('Origin, Accept-Language, Accept-Encoding', $response->headers->get('Vary'));
    }

    #[Test]
    public function test_locale_is_applied_before_calling_next(): void
    {
        [, $downstream] = $this->dispatch('es');

        $this->assertSame('es', $downstream);
    }

    #[Test]
    public function test_downstream_response_is_returned_unchanged_apart_from_headers(): void
    {
        [$response] = $this->dispatch('fr');

        $this->assertSame('ok', $response->getContent());
        $this->assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function test_supported_map_exposes_the_advertised_locales(): void
    {
        $this->assertSame(
            ['en', 'fr', 'pt-br', 'pt_br', 'it', 'es'],
            array_keys(SetLocale::SUPPORTED),
        );
    }
}
