<?php

namespace App\Infrastructure\External\PokeApi;

use App\Application\Creatures\DTOs\ExternalCreatureData;
use Illuminate\Support\Str;

final class PokeApiCreatureMapper
{
    private const PROVIDER = 'pokeapi';

    /**
     * Trusted hosts for provider artwork URLs (demo/dev only).
     *
     * @var list<string>
     */
    private const TRUSTED_IMAGE_HOSTS = [
        'raw.githubusercontent.com',
    ];

    private const STAT_MAP = [
        'hp' => 'baseHp',
        'attack' => 'baseAttack',
        'defense' => 'baseDefense',
        'special-attack' => 'baseSpecialAttack',
        'special-defense' => 'baseSpecialDefense',
        'speed' => 'baseSpeed',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function map(array $payload): ExternalCreatureData
    {
        $externalId = (int) ($payload['id'] ?? 0);
        $name = (string) ($payload['name'] ?? '');
        $slug = Str::slug($name);

        $stats = [
            'baseHp' => 0,
            'baseAttack' => 0,
            'baseDefense' => 0,
            'baseSpecialAttack' => 0,
            'baseSpecialDefense' => 0,
            'baseSpeed' => 0,
        ];

        foreach ($payload['stats'] ?? [] as $statRow) {
            if (! is_array($statRow)) {
                continue;
            }

            $statName = (string) data_get($statRow, 'stat.name', '');
            $property = self::STAT_MAP[$statName] ?? null;
            if ($property === null) {
                continue;
            }

            $stats[$property] = (int) ($statRow['base_stat'] ?? 0);
        }

        $types = [];
        foreach ($payload['types'] ?? [] as $typeRow) {
            if (! is_array($typeRow)) {
                continue;
            }

            $typeName = (string) data_get($typeRow, 'type.name', '');
            if ($typeName === '') {
                continue;
            }

            $types[] = [
                'name' => Str::title(str_replace('-', ' ', $typeName)),
                'slug' => Str::slug($typeName),
                'slot' => (int) ($typeRow['slot'] ?? 0),
            ];
        }

        usort($types, fn (array $a, array $b) => $a['slot'] <=> $b['slot']);

        $heightDm = array_key_exists('height', $payload) ? (int) $payload['height'] : null;
        $weightHg = array_key_exists('weight', $payload) ? (int) $payload['weight'] : null;

        return new ExternalCreatureData(
            provider: self::PROVIDER,
            externalId: $externalId,
            name: Str::title(str_replace('-', ' ', $name)),
            slug: $slug,
            heightCm: $heightDm === null ? null : $heightDm * 10,
            weightG: $weightHg === null ? null : $weightHg * 100,
            baseHp: $stats['baseHp'],
            baseAttack: $stats['baseAttack'],
            baseDefense: $stats['baseDefense'],
            baseSpecialAttack: $stats['baseSpecialAttack'],
            baseSpecialDefense: $stats['baseSpecialDefense'],
            baseSpeed: $stats['baseSpeed'],
            types: $types,
            imageRef: $this->resolveImageUrl($payload),
        );
    }

    /**
     * Prefer official artwork, then home, then default sprite.
     * Never invent a URL from the creature ID when the payload omits sprites.
     *
     * @param  array<string, mixed>  $payload
     */
    private function resolveImageUrl(array $payload): ?string
    {
        $candidates = [
            data_get($payload, 'sprites.other.official-artwork.front_default'),
            data_get($payload, 'sprites.other.home.front_default'),
            data_get($payload, 'sprites.front_default'),
        ];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $url = trim($candidate);
            if ($url !== '' && $this->isTrustedImageUrl($url)) {
                return $url;
            }
        }

        return null;
    }

    private function isTrustedImageUrl(string $url): bool
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

        foreach (self::TRUSTED_IMAGE_HOSTS as $trustedHost) {
            if ($host === $trustedHost || str_ends_with($host, '.'.$trustedHost)) {
                return true;
            }
        }

        return false;
    }
}
