# Battle Arena API

Laravel 13 REST API for Battle Arena.

## Stack

- PHP 8.4 / Laravel 13
- PostgreSQL
- Redis
- Optional S3-compatible storage (RustFS via `battle-arena-infra --profile storage`)

## Why the catalog is persisted locally

PokéAPI is an external seed source only. Catalog pages and clients must not call PokéAPI at request time because:

- Upstream availability and rate limits must not affect gameplay UX
- Response shapes can change; the domain keeps a stable internal model
- Future providers or a hand-authored catalog can replace PokéAPI without API contract changes
- We avoid shipping or mirroring copyrighted artwork from the provider

Import once (or on demand) into PostgreSQL. Serve reads from Laravel.

## Domain structure

```
app/
  Domain/Creatures/Models/     # Creature, CreatureType (Eloquent)
  Application/Creatures/       # Provider contract, DTOs, import + catalog services
  Infrastructure/External/PokeApi/  # HTTP client, mapper, provider adapter
  Http/Controllers/Api/        # Thin controllers
  Http/Resources/              # Public JSON shapes
  Console/Commands/            # creatures:import
```

**Architectural decision:** the application depends on `CreatureCatalogProvider`, not PokéAPI JSON. `PokeApiCreatureProvider` maps upstream payloads into `ExternalCreatureData` before persistence.

### Species vs playable variety

The initial `creatures` table stores a **playable variety** (concrete stats + types), matching provider “pokemon” records. Abstract species and alternate forms are intentionally deferred. A later `species_id` (or form discriminator) can group varieties without changing list/detail JSON much.

## Database model

| Table | Purpose |
|-------|---------|
| `creatures` | Catalog varieties (unique `provider` + `external_id`, unique `slug`) |
| `creature_types` | Type dictionary (`slug` unique) |
| `creature_type` | Many-to-many with ordered `slot` |

Numeric fields: unsigned integers for stats; `height_cm` / `weight_g` nullable; `image_ref` optional and separate from combat data.

## Provider abstraction

```php
interface CreatureCatalogProvider {
    public function providerName(): string;
    /** @param list<int> $externalIds */
    public function fetchByExternalIds(array $externalIds): array; // ExternalCreatureData[]
}
```

Concrete: `PokeApiCreatureProvider` (`https://pokeapi.co/api/v2/`).

Configured in `config/creatures.php` (timeout, retries, delay, cache TTL).

## Import process

Deterministic starter set (20 Gen I IDs):

`1, 2, 3, 4, 5, 6, 7, 8, 9, 25, 39, 52, 54, 66, 74, 92, 129, 133, 143, 147`

```bash
php artisan creatures:import
php artisan creatures:import --ids=1,4,7
```

Import properties:

- Idempotent upsert on `(provider, external_id)`
- Incomplete upstream payloads are skipped; existing rows are not replaced with partial data
- Temporary upstream failures abort without corrupting the catalog
- Does not download or commit copyrighted sprites (`image_ref` stays null)

## Endpoints

| Method | Path | Notes |
|--------|------|-------|
| `GET` | `/api/health` | Liveness |
| `GET` | `/api/ready` | PostgreSQL + Redis |
| `GET` | `/api/creatures` | Paginated catalog (`search`, `type`, `page`, `per_page` ≤ 50) |
| `GET` | `/api/creatures/{slug}` | Detail |
| `GET` | `/api/creature-types` | Filter dictionary |

All catalog endpoints are public and read-only in this phase.

### Example: list

```http
GET /api/creatures?search=char&type=fire&per_page=12
```

```json
{
  "data": [
    {
      "id": 1,
      "name": "Charmander",
      "slug": "charmander",
      "height_cm": 60,
      "weight_g": 8500,
      "image_ref": null,
      "types": [{ "id": 1, "name": "Fire", "slug": "fire" }],
      "base_stats": {
        "hp": 39,
        "attack": 52,
        "defense": 43,
        "special_attack": 60,
        "special_defense": 50,
        "speed": 65
      }
    }
  ],
  "links": {},
  "meta": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 12,
    "total": 1
  }
}
```

### Example: detail

```http
GET /api/creatures/bulbasaur
```

```json
{
  "data": {
    "id": 1,
    "name": "Bulbasaur",
    "slug": "bulbasaur",
    "height_cm": 70,
    "weight_g": 6900,
    "image_ref": null,
    "types": [
      { "id": 1, "name": "Grass", "slug": "grass" },
      { "id": 2, "name": "Poison", "slug": "poison" }
    ],
    "base_stats": {
      "hp": 45,
      "attack": 49,
      "defense": 49,
      "special_attack": 65,
      "special_defense": 65,
      "speed": 45
    }
  }
}
```

## Local with Docker

```bash
cd ../battle-arena-infra
docker compose up --build -d
docker compose exec api php artisan migrate
docker compose exec api php artisan creatures:import
```

- API: http://localhost:8000
- Optional S3: `docker compose --profile storage up -d`

## Testing

```bash
php artisan test
# or
docker compose exec api php artisan test
```

Import/provider tests use `Http::fake` fixtures under `tests/Fixtures/pokeapi`. They do not call live PokéAPI.

`phpunit.xml` overrides both `<env force="true">` and `<server>` for `DB_*`. Compose injects database settings into `$_SERVER`, and Laravel reads `$_SERVER` before `$_ENV`, so server overrides are required to keep `RefreshDatabase` on SQLite instead of wiping local PostgreSQL.

## Known limitations

- No authentication, owned creatures, battles, moves, or evolution
- Display art uses client-side neutral placeholders
- Alternate forms / species grouping not modeled yet
- Storage profile is optional and unused by catalog endpoints
