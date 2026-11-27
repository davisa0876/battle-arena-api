<?php

declare(strict_types=1);

namespace App\Infrastructure\External\PokeApi;

use App\Application\Battles\DTO\ExternalLearnableMoveRef;
use App\Application\Battles\DTO\ExternalMoveData;

final class PokeApiCombatMapper
{
    private const PROVIDER = 'pokeapi';

    /**
     * Prefer the pinned version group; if the species is absent there (e.g. Absol in SV),
     * fall back through recent groups so catalog creatures still get a learnset.
     *
     * @var list<string>
     */
    private const VERSION_GROUP_FALLBACKS = [
        'sword-shield',
        'ultra-sun-ultra-moon',
        'omega-ruby-alpha-sapphire',
        'x-y',
        'black-2-white-2',
        'black-white',
        'heartgold-soulsilver',
        'platinum',
        'diamond-pearl',
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public function mapMove(array $payload): ExternalMoveData
    {
        $slug = (string) ($payload['name'] ?? '');
        $externalId = (int) ($payload['id'] ?? 0);
        $damageClass = (string) data_get($payload, 'damage_class.name', '');
        $typeSlug = (string) data_get($payload, 'type.name', '');

        return new ExternalMoveData(
            provider: self::PROVIDER,
            externalId: $externalId,
            slug: $slug,
            name: $this->englishName($payload) ?? $this->titleCaseSlug($slug),
            power: isset($payload['power']) && is_numeric($payload['power']) ? (int) $payload['power'] : null,
            accuracy: isset($payload['accuracy']) && is_numeric($payload['accuracy']) ? (int) $payload['accuracy'] : null,
            pp: max(0, (int) ($payload['pp'] ?? 0)),
            damageClass: $damageClass,
            typeSlug: $typeSlug,
            priority: (int) ($payload['priority'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $pokemonPayload
     * @return list<ExternalLearnableMoveRef>
     */
    public function learnsetForVersionGroup(array $pokemonPayload, string $versionGroup): array
    {
        $preferred = $this->extractForVersionGroup($pokemonPayload, $versionGroup);
        if ($preferred !== []) {
            return $preferred;
        }

        foreach (self::VERSION_GROUP_FALLBACKS as $fallback) {
            if ($fallback === $versionGroup) {
                continue;
            }
            $refs = $this->extractForVersionGroup($pokemonPayload, $fallback);
            if ($refs !== []) {
                return $refs;
            }
        }

        return $this->extractBestAcrossAllVersionGroups($pokemonPayload);
    }

    /**
     * @param  array<string, mixed>  $pokemonPayload
     * @return list<ExternalLearnableMoveRef>
     */
    private function extractForVersionGroup(array $pokemonPayload, string $versionGroup): array
    {
        $byMoveId = [];

        foreach ($pokemonPayload['moves'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $moveUrl = (string) data_get($row, 'move.url', '');
            $moveSlug = (string) data_get($row, 'move.name', '');
            if ($moveSlug === '' || preg_match('#/move/(\d+)/?#', $moveUrl, $matches) !== 1) {
                continue;
            }
            $moveExternalId = (int) $matches[1];

            $best = null;
            foreach ($row['version_group_details'] ?? [] as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                if ((string) data_get($detail, 'version_group.name', '') !== $versionGroup) {
                    continue;
                }

                $method = (string) data_get($detail, 'move_learn_method.name', '');
                $level = isset($detail['level_learned_at']) ? (int) $detail['level_learned_at'] : null;
                $candidate = [
                    'method' => $method !== '' ? $method : null,
                    'level' => $level,
                ];

                if ($best === null || $this->isBetterLearnDetail($candidate, $best)) {
                    $best = $candidate;
                }
            }

            if ($best === null) {
                continue;
            }

            $byMoveId[$moveExternalId] = new ExternalLearnableMoveRef(
                moveExternalId: $moveExternalId,
                moveSlug: $moveSlug,
                learnLevel: $best['level'],
                learnMethod: $best['method'],
            );
        }

        return array_values($byMoveId);
    }

    /**
     * Last resort: one best detail per move across every version group present.
     *
     * @param  array<string, mixed>  $pokemonPayload
     * @return list<ExternalLearnableMoveRef>
     */
    private function extractBestAcrossAllVersionGroups(array $pokemonPayload): array
    {
        $byMoveId = [];

        foreach ($pokemonPayload['moves'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $moveUrl = (string) data_get($row, 'move.url', '');
            $moveSlug = (string) data_get($row, 'move.name', '');
            if ($moveSlug === '' || preg_match('#/move/(\d+)/?#', $moveUrl, $matches) !== 1) {
                continue;
            }
            $moveExternalId = (int) $matches[1];

            $best = null;
            foreach ($row['version_group_details'] ?? [] as $detail) {
                if (! is_array($detail)) {
                    continue;
                }
                $method = (string) data_get($detail, 'move_learn_method.name', '');
                $level = isset($detail['level_learned_at']) ? (int) $detail['level_learned_at'] : null;
                $candidate = [
                    'method' => $method !== '' ? $method : null,
                    'level' => $level,
                ];
                if ($best === null || $this->isBetterLearnDetail($candidate, $best)) {
                    $best = $candidate;
                }
            }

            if ($best === null) {
                continue;
            }

            $byMoveId[$moveExternalId] = new ExternalLearnableMoveRef(
                moveExternalId: $moveExternalId,
                moveSlug: $moveSlug,
                learnLevel: $best['level'],
                learnMethod: $best['method'],
            );
        }

        return array_values($byMoveId);
    }

    /**
     * @param  array{method:?string, level:?int}  $candidate
     * @param  array{method:?string, level:?int}  $current
     */
    private function isBetterLearnDetail(array $candidate, array $current): bool
    {
        $candidateLevelUp = ($candidate['method'] ?? '') === 'level-up';
        $currentLevelUp = ($current['method'] ?? '') === 'level-up';
        if ($candidateLevelUp !== $currentLevelUp) {
            return $candidateLevelUp;
        }

        $candidateLevel = $candidate['level'] ?? PHP_INT_MAX;
        $currentLevel = $current['level'] ?? PHP_INT_MAX;

        return $candidateLevel < $currentLevel;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function englishName(array $payload): ?string
    {
        foreach ($payload['names'] ?? [] as $row) {
            if (! is_array($row)) {
                continue;
            }
            if ((string) data_get($row, 'language.name', '') === 'en') {
                $name = trim((string) ($row['name'] ?? ''));

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    private function titleCaseSlug(string $slug): string
    {
        return collect(explode('-', $slug))
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)).mb_substr($part, 1))
            ->implode(' ');
    }
}
