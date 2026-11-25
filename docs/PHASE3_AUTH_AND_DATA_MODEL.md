# Phase 3 — Authentication & Data Model Decisions

## Authentication

| Client | Mechanism | Storage | Logout |
|--------|-----------|---------|--------|
| Web SPA | Sanctum **session cookies** (stateful) | Browser cookies only (HttpOnly session + XSRF) | Invalidate session |
| Mobile | Sanctum **personal access tokens** | Expo SecureStore | Revoke current token |

- Web never receives or stores bearer tokens.
- Mobile never relies on cookies.
- Routes under `auth:sanctum` accept either cookie (stateful origin) or `Authorization: Bearer`.
- Login/register body includes optional `device_name`. When present → token auth response. When absent → session auth (web).
- CSRF: web calls `GET /sanctum/csrf-cookie` before mutating auth requests.
- CORS: `supports_credentials=true`; origins from `FRONTEND_URL`.
- `SANCTUM_STATEFUL_DOMAINS=localhost:3000,127.0.0.1:3000`
- Session regenerate on successful web login; invalidate on logout.
- Rate limits: login, register, password recovery.
- Email verification + password reset via Mailpit SMTP in local Compose.
- No Google OAuth. No ToS checkbox.

## Data model (additive migrations)

Reuse `users` (email unique, hashed password, `email_verified_at`).

- `player_profiles` — 1:1 user; unique case-insensitive `handle`; display_name; bio; avatar_path; avatar_disk; collection_is_public (default false)
- `player_progress` — 1:1 user; level default 1; total_xp default 0
- `player_xp_events` — auditable awards; unique `idempotency_key`
- `owned_creatures` — belongs to user + creature; level/xp; `is_active`; partial unique: one active per user
- `creature_evolution_history` — history only; evolution execution deferred

Registration creates User + Profile + Progress in one transaction.

## Avatar storage

- Disk resolution: `PROFILES_DISK` env (`profiles` S3 when storage profile up, else `profiles_local`).
- Local fallback: `storage/app/profiles` with Docker volume `api_profiles`.
- Validate JPG/PNG/WebP; max size/dimensions; reject SVG; re-encode with GD; strip metadata; random object names.
- Delivery: `GET /api/avatars/{userId}` (public for public profiles / owner) streams from private disk — works for web, emulators, LAN devices. No S3 credentials to clients.
- Login/profile do not require RustFS.

## Trainer progression

- Formula (config): `ceil(100 * 1.5 ^ (level - 1))` XP to advance from `level` → `level+1`.
- Service: `TrainerProgressionService` — award XP with idempotency + row lock; no public self-grant endpoint.
- Dev-only Artisan command for awarding XP (local/testing).

## Starters & collection

- Configurable slug list in `config/game.php` (stage-1 base forms only).
- `StarterSelectionService` — idempotent; concurrent-safe (transaction + lock).
- Evolution forms visible but locked; no evolution execution.

## Game rules

- `GET /api/game/rules` — versioned machine-readable feature flags + XP curve samples.
- Web `/game-rules` and mobile Game Rules screen consume this + i18n copy.
