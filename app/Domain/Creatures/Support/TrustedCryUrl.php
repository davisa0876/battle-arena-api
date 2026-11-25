<?php

declare(strict_types=1);

namespace App\Domain\Creatures\Support;

/**
 * Validates cry audio URLs from trusted PokéAPI CDN hosts only.
 */
final class TrustedCryUrl
{
    /**
     * @var list<string>
     */
    private const TRUSTED_HOSTS = [
        'raw.githubusercontent.com',
    ];

    public static function normalize(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $trimmed = trim($url);
        if ($trimmed === '' || ! self::isTrusted($trimmed)) {
            return null;
        }

        return $trimmed;
    }

    public static function prefer(?string $latest, ?string $legacy): ?string
    {
        return self::normalize($latest) ?? self::normalize($legacy);
    }

    public static function isTrusted(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts)) {
            return false;
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $path = (string) ($parts['path'] ?? '');
        if (! str_contains($path, '/PokeAPI/cries/')) {
            return false;
        }

        foreach (self::TRUSTED_HOSTS as $trustedHost) {
            if ($host === $trustedHost || str_ends_with($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }
}
