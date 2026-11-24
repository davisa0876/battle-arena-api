<?php

namespace App\Application\Creatures\Services;

use App\Domain\Creatures\Models\Creature;
use App\Domain\Creatures\Models\CreatureType;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class CreatureCatalogService
{
    public const DEFAULT_PER_PAGE = 12;

    public const MAX_PER_PAGE = 50;

    /**
     * Catalog lists stage-1 (base) forms only.
     *
     * @param  array{search?: string|null, type?: string|null, per_page?: int|null, page?: int|null}  $filters
     */
    public function paginate(array $filters = []): LengthAwarePaginator
    {
        $perPage = min(
            max((int) ($filters['per_page'] ?? self::DEFAULT_PER_PAGE), 1),
            self::MAX_PER_PAGE,
        );

        $query = Creature::query()
            ->baseForms()
            ->with(['types'])
            ->orderBy('name')
            ->orderBy('id');

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->whereRaw(
                "LOWER(name) LIKE ? ESCAPE '\\'",
                ['%'.mb_strtolower($this->escapeLike($search)).'%']
            );
        }

        $typeSlug = trim((string) ($filters['type'] ?? ''));
        if ($typeSlug !== '') {
            $query->whereHas('types', function (Builder $builder) use ($typeSlug) {
                $builder->where('creature_types.slug', $typeSlug);
            });
        }

        return $query->paginate($perPage)->withQueryString();
    }

    public function findBySlug(string $slug): ?Creature
    {
        return Creature::query()
            ->with([
                'types',
                'evolvesFrom.types',
                'evolutions.types',
                'evolutions.evolutions.types',
            ])
            ->where('slug', $slug)
            ->first();
    }

    /**
     * @return Collection<int, CreatureType>
     */
    public function listTypes(): Collection
    {
        return CreatureType::query()
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
