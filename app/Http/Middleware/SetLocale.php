<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Externally advertised locales mapped to Laravel locale identifiers.
     *
     * @var array<string, string>
     */
    public const SUPPORTED = [
        'en' => 'en',
        'fr' => 'fr',
        'pt-br' => 'pt_BR',
        'pt_br' => 'pt_BR',
        'it' => 'it',
        'es' => 'es',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->negotiate($request->header('Accept-Language'));
        app()->setLocale($locale);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Language', $this->toExternalLocale($locale));
        $response->headers->set('Vary', $this->mergeVary($response->headers->get('Vary'), 'Accept-Language'));

        return $response;
    }

    private function negotiate(?string $header): string
    {
        if ($header === null || trim($header) === '') {
            return 'en';
        }

        $candidates = [];
        foreach (explode(',', $header) as $part) {
            $segment = trim($part);
            if ($segment === '') {
                continue;
            }

            [$tag, $params] = array_pad(explode(';', $segment, 2), 2, '');
            $quality = 1.0;
            if (is_string($params) && preg_match('/q=([0-9.]+)/i', $params, $matches) === 1) {
                $quality = (float) $matches[1];
            }

            $candidates[] = ['tag' => strtolower(trim($tag)), 'q' => $quality];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['q'] <=> $a['q']);

        foreach ($candidates as $candidate) {
            $mapped = $this->mapTag($candidate['tag']);
            if ($mapped !== null) {
                return $mapped;
            }
        }

        return 'en';
    }

    private function mapTag(string $tag): ?string
    {
        $normalized = str_replace('_', '-', $tag);

        if (isset(self::SUPPORTED[$normalized])) {
            return self::SUPPORTED[$normalized];
        }

        $primary = explode('-', $normalized)[0];
        if (isset(self::SUPPORTED[$primary])) {
            return self::SUPPORTED[$primary];
        }

        // Portuguese without region maps to pt_BR for this product.
        if ($primary === 'pt') {
            return 'pt_BR';
        }

        return null;
    }

    private function toExternalLocale(string $laravelLocale): string
    {
        return match ($laravelLocale) {
            'pt_BR' => 'pt-BR',
            default => $laravelLocale,
        };
    }

    private function mergeVary(?string $existing, string $value): string
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $existing)));
        if (! in_array($value, $parts, true)) {
            $parts[] = $value;
        }

        return implode(', ', $parts);
    }
}
