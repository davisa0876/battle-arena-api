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

## Images

Import maps PokéAPI sprite fields into `creatures.image_ref` with fallback order:

1. `sprites.other.official-artwork.front_default`
2. `sprites.other.home.front_default`
3. `sprites.front_default`
4. `null` (clients show a neutral placeholder)

Only trusted HTTPS hosts are accepted (`raw.githubusercontent.com`). API responses expose both `image_url` and `image_ref`. Re-running `php artisan creatures:import` backfills images and never clears a valid stored URL with null.

These remote URLs are **demo-only** third-party artwork. Do not commit sprite binaries or upload them to S3.

## API localization

`SetLocale` middleware reads `Accept-Language` (`en`, `fr`, `pt-BR`, `it`, `es`), sets Laravel locale for the request, and returns `Content-Language` + `Vary: Accept-Language`.

Lang files: `lang/{en,fr,pt_BR,it,es}/`.

JSON field names stay English. Creature **names** currently use the canonical imported name (not per-locale DB rows) in this phase.

## Phase 3: accounts, profiles, collection

Full decisions live in `docs/PHASE3_AUTH_AND_DATA_MODEL.md`; the HTTP contract is built from Horus-style OpenAPI fragments.

## Swagger (OpenAPI UI)

- Source of truth (Horus-style): `resources/docs/swagger/{paths,components}/*.json`
- Build/publish: `php artisan openapi:sync` → `docs/openapi.yaml`, `docs/openapi.json`, `storage/api-docs/`
- Live JSON: `GET /api/swagger.json`
- UI: [http://localhost:8000/api/documentation](http://localhost:8000/api/documentation) (`darkaonline/l5-swagger`, Sanctum bearer enabled)

When you change request/response shapes, edit the JSON fragments under `resources/docs/swagger/` and run `openapi:sync` again.

## Telescope

- Local debugging UI: [http://localhost:8000/telescope](http://localhost:8000/telescope)
- Installed as a **dev** dependency and registered only when `APP_ENV=local`
- Toggle with `TELESCOPE_ENABLED` (disabled automatically in PHPUnit)
- Entries older than 24h are pruned daily via `telescope:prune`

### Endpoints

| Method | Path | Auth | Notes |
|--------|------|------|-------|
| `POST` | `/api/register` | public, throttled | Creates user + profile + level-1 progress in one transaction |
| `POST` | `/api/login` | public, throttled | Session (web) or token (when `device_name` is sent) |
| `POST` | `/api/logout` | sanctum | Revokes the current token or ends the session |
| `POST` | `/api/forgot-password`, `/api/reset-password` | public, throttled | Reset link points at `FRONTEND_PASSWORD_RESET_URL` |
| `GET` | `/api/email/verify/{id}/{hash}` | signed URL | Verifies; redirects browsers to `FRONTEND_EMAIL_VERIFIED_URL?status=verified` |
| `POST` | `/api/email/verification-notification` | sanctum | Resend verification email |
| `GET` | `/api/me` | sanctum | Account, profile, trainer progress, onboarding state |
| `PATCH` | `/api/me/profile` | sanctum | `handle`, `display_name`, `bio`, `collection_is_public` |
| `PATCH` | `/api/me/password` | sanctum | Revokes other tokens |
| `POST` / `DELETE` | `/api/me/avatar` | sanctum | Multipart field `avatar` (JPG/PNG/WebP) |
| `GET` | `/api/avatars/{userId}` | public | Streams the avatar from the private disk |
| `GET` | `/api/players/{handle}` | public | Public profile; collection only if `collection_is_public` |
| `GET` | `/api/me/creatures`, `/api/me/creatures/{id}` | sanctum | Owned creatures (evolutions listed but locked) |
| `POST` | `/api/me/starter` | sanctum | Body `creature_slug`; once per trainer (409 afterwards) |
| `PATCH` | `/api/me/creatures/{id}/select` | sanctum | Makes the creature the single active one |
| `GET` | `/api/game/rules` | public | Versioned rules, feature flags, XP curve |

There is intentionally **no HTTP endpoint that grants XP**.

### Authentication flows

- **Web SPA (cookie session).** Call `GET /sanctum/csrf-cookie`, then `POST /api/login` (or `/api/register`) **without** `device_name`, with `credentials: 'include'` and the `X-XSRF-TOKEN` header. The browser origin must be listed in `SANCTUM_STATEFUL_DOMAINS` and `FRONTEND_URL` (CORS, `supports_credentials=true`). The session id is regenerated on login and invalidated on logout. No bearer token is ever returned to the web.
- **Mobile (bearer token).** Send `device_name` (for example `"Pixel 9"`). The response `meta.token` is a Sanctum personal access token; send it as `Authorization: Bearer <token>` and keep it in SecureStore. `POST /api/logout` revokes only that device's token. Mobile requests never rely on cookies.
- Routes under `auth:sanctum` accept either mechanism. Non-browser clients that omit `device_name` get a `422`.
- Login, register and password recovery are rate limited (`config/game.php` `rate_limits`).
- Password reset revokes every personal access token; changing the password revokes all other tokens.

### Email (Mailpit)

Compose runs Mailpit (SMTP `mailpit:1025`, UI http://localhost:8025). `.env.example` already points `MAIL_*` at it. Registration and `POST /api/email/verification-notification` send Laravel's built-in `VerifyEmail` notification containing a **signed API URL** (`/api/email/verify/{id}/{hash}?expires=&signature=`). The same link works on web and mobile: browsers are redirected to `FRONTEND_EMAIL_VERIFIED_URL`, API clients (`Accept: application/json`) get JSON. `ResetPassword` links point to `FRONTEND_PASSWORD_RESET_URL?token=&email=`. Verification is not required to use the API yet. Make sure `APP_URL` is reachable from the device that opens the email.

### Avatars

- Upload validation: JPG/PNG/WebP only (SVG rejected), at most 2048 KB, 32-1024 px per side (`config/game.php` `avatar`).
- `AvatarImageProcessor` (GD) decodes, center-crops to a square, resizes to 512 px and re-encodes (WebP, or JPEG when WebP is unavailable), which strips metadata. The original bytes are never stored. Objects get random names under `avatars/`.
- Disk is chosen by `PROFILES_DISK`:
  - `profiles_local` (default): `storage/app/profiles`, private, backed by the `api_profiles` Docker volume.
  - `profiles`: private S3 bucket (RustFS) when `docker compose --profile storage up -d` is running.
- Clients never get storage credentials; they load `profile.avatar_url`, which is `GET /api/avatars/{userId}`. Avatars are treated as profile photos (visible to anyone holding the URL); profiles never expose email.
- The Docker image needs the `gd` extension. Rebuild after pulling the Dockerfile change: `docker compose build api`. Without GD, uploads return `503`.

### Data model and commands

New tables: `player_profiles`, `player_progress`, `player_xp_events`, `owned_creatures`, `creature_evolution_history` (all additive; handles are stored lowercase so a plain unique index is case-insensitive). "One active creature per trainer" is enforced by a unique `owned_creatures.active_user_id` column (equals `user_id` while active, otherwise `NULL`), working on both SQLite and PostgreSQL, plus a transaction with row locks in the services.

```bash
# Apply the new migrations (never use migrate:fresh on shared data)
docker compose exec api php artisan migrate
docker compose exec api php artisan creatures:import      # starters need the catalog

# Local/testing only: award trainer XP (user = id, email or handle)
docker compose exec api php artisan trainer:award-xp misty 250 --source=dev --key=my-key
```

XP needed to advance from `level` to `level + 1` is `ceil(100 * 1.5 ^ (level - 1))` (100, 150, 225, 338, ...). Starters are limited to the stage-1 slugs in `config/game.php` and must be base forms in the catalog.

## Phase 5B.1 — Private PvP lobbies

Authoritative private 1:1 lobbies live in Laravel (`/api/pvp/*`). Trainers must be level ≥ 6. Invitations resolve by exact `PlayerProfile.handle`. Node only relays post-commit notification hints after clients authenticate with realtime tickets and redeem one-use room subscription grants.

```bash
docker compose exec api php artisan migrate
docker compose exec api php artisan openapi:sync
docker compose exec api php artisan test --filter=Pvp
```

`features.pvp` remains `false` until combat ships. Lobby leave/cancel never awards XP.

Spec: `docs/PHASE5B_PVP_PRIVATE_ROOMS_SPEC_EN.md`.

## Known limitations

- PvP combat, matchmaking, and rankings are not implemented (private lobby/invite only)
- Evolution execution remains locked
- Creature/type names are not yet domain-localized; UI chrome is fully translated on clients
- Alternate forms / species grouping not modeled yet
- Storage profile is optional and unused by catalog endpoints
- Persistent Cursor rules live in `.cursor/rules/`
