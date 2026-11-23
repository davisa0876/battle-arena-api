# Battle Arena API

Laravel 13 REST API for Battle Arena (Phase 1 bootstrap).

## Stack

- PHP 8.4
- Laravel 13
- PostgreSQL
- Redis

## Endpoints

- `GET /api/health` — liveness (no DB/Redis dependency)
- `GET /api/ready` — readiness (PostgreSQL + Redis)

## Local with Docker

Prefer starting from `battle-arena-infra`:

```bash
cd ../battle-arena-infra
docker compose up --build -d
```

API: http://localhost:8000

## Commands

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan test
php artisan serve --host=0.0.0.0 --port=8000
```

## Environment

See `.env.example`. Inside Compose, use `DB_HOST=postgres` and `REDIS_HOST=redis`.
