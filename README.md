# Battle Arena API

Laravel 13 REST API for Battle Arena (Phase 1 bootstrap).

## Stack

- PHP 8.4
- Laravel 13
- PostgreSQL
- Redis
- S3-compatible storage (RustFS via `battle-arena-infra`) for profile photos

## Endpoints

- `GET /api/health` — liveness (no DB/Redis dependency)
- `GET /api/ready` — readiness (PostgreSQL + Redis)

## Local with Docker

Prefer starting from `battle-arena-infra`:

```bash
cd ../battle-arena-infra
docker compose up --build -d
```

- API: http://localhost:8000
- S3 console: http://localhost:9001/rustfs/console/

## Profile photos (S3)

Compose wires the API to the local S3 service (`minio` / RustFS).

| Disk | Use |
|------|-----|
| `s3` | Default cloud disk (`FILESYSTEM_DISK=s3`) |
| `profiles` | Profile photos under `profiles/` in bucket `battle-arena-profiles` |

```php
Storage::disk('profiles')->put($path, $contents);
$url = Storage::disk('profiles')->url($path);
```

Inside Compose:

- `AWS_ENDPOINT=http://minio:9000`
- `AWS_URL=http://localhost:9000/battle-arena-profiles`
- `AWS_USE_PATH_STYLE_ENDPOINT=true`

Credentials and bucket defaults live in `.env.example` and in `battle-arena-infra` `.env`.

## Commands

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
php artisan serve --host=0.0.0.0 --port=8000
```

## Environment

See `.env.example`. Inside Compose use `DB_HOST=postgres`, `REDIS_HOST=redis`, and `AWS_ENDPOINT=http://minio:9000`.
