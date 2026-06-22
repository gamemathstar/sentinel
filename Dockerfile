# Single app image, configured per process-role via env (docs/02-system-architecture.md §2).
# Uses the official PHP CLI image with the extensions Laravel + Postgres + Redis need.
FROM php:8.3-cli-alpine

# System deps + PHP extensions: pdo_pgsql (Postgres), redis (phpredis), intl, zip, gd.
RUN apk add --no-cache \
        postgresql-dev libzip-dev icu-dev oniguruma-dev libpng-dev git unzip \
    && docker-php-ext-install pdo pdo_pgsql bcmath intl zip gd \
    && pecl install redis \
    && docker-php-ext-enable redis

# Composer from the official image.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first for layer caching, then copy the rest.
COPY composer.json composer.lock ./
RUN composer install --no-interaction --no-scripts --prefer-dist --no-dev || true

COPY . .
RUN composer install --no-interaction --prefer-dist --no-dev \
    && composer dump-autoload --optimize

EXPOSE 8000

# Default command is overridden per role in docker-compose.yml.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
