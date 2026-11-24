#!/bin/sh
# Safe container bootstrap for Battle Arena API.
# Never regenerates an existing APP_KEY. Never hides init failures with "|| true".
set -eu

cd /var/www/html

echo "[entrypoint] Installing Composer dependencies..."
composer install --prefer-dist --no-interaction --no-progress

if [ ! -f .env ]; then
  echo "[entrypoint] Creating .env from .env.example..."
  cp .env.example .env
fi

ensure_app_key() {
  # Prefer the runtime environment (Compose env_file / environment).
  if [ -n "${APP_KEY:-}" ] && printf '%s' "$APP_KEY" | grep -Eq '^base64:[A-Za-z0-9+/=]+$'; then
    echo "[entrypoint] APP_KEY already present in environment."
    return 0
  fi

  if grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]+' .env; then
    echo "[entrypoint] APP_KEY already present in .env."
    return 0
  fi

  echo "[entrypoint] Generating APP_KEY for first-time initialization..."
  # Do not pass --force: Laravel will refuse to overwrite an existing key.
  php artisan key:generate --no-interaction

  if ! grep -Eq '^APP_KEY=base64:[A-Za-z0-9+/=]+' .env; then
    echo "[entrypoint] ERROR: APP_KEY is missing or invalid after key:generate." >&2
    exit 1
  fi
}

ensure_app_key

# Fail fast if Laravel cannot resolve a usable key (covers empty/invalid env overrides).
php -r '
  require "vendor/autoload.php";
  $app = require "bootstrap/app.php";
  $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
  $key = (string) config("app.key");
  if ($key === "" || ! preg_match("/^base64:[A-Za-z0-9+\/=]+$/", $key)) {
      fwrite(STDERR, "[entrypoint] ERROR: Application key is missing or invalid. Refusing to start.\n");
      exit(1);
  }
  echo "[entrypoint] Application key validated.\n";
'

echo "[entrypoint] Starting Laravel development server..."
exec php artisan serve --host=0.0.0.0 --port=8000
