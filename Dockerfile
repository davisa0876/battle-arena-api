FROM php:8.4-cli-bookworm

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
        libicu-dev \
        $PHPIZE_DEPS \
    && docker-php-ext-install pdo_pgsql pgsql zip intl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get purge -y $PHPIZE_DEPS \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

COPY . .

RUN composer install --prefer-dist --no-interaction --no-progress \
    && php artisan package:discover --ansi || true

EXPOSE 8000

CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
