# syntax=docker/dockerfile:1

# ---------------------------------------------------------------------------
# Stage 1: PHP dependencies (composer, no dev)
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-interaction \
    --no-progress \
    --prefer-dist \
    --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2: Frontend assets (Vite build)
# ---------------------------------------------------------------------------
FROM node:22-alpine AS assets

WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tsconfig.json tailwind.config.js* postcss.config.js* components.json ./
COPY resources/ ./resources/
COPY public/ ./public/

RUN npm run build

# ---------------------------------------------------------------------------
# Stage 3: Runtime — FrankenPHP (worker mode via Octane)
# ---------------------------------------------------------------------------
FROM dunglas/frankenphp:1.13-php8.5 AS app

RUN install-php-extensions \
    pcntl \
    intl \
    pdo_pgsql \
    pgsql \
    zip \
    bcmath \
    opcache

COPY --from=vendor /app/vendor/ /app/vendor/
COPY --from=assets /app/public/build/ /app/public/build/

COPY . /app
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/entrypoint.sh
COPY docker/php.ini /usr/local/etc/php/conf.d/99-taskr.ini

RUN sed -i 's/^;clear_env = no/clear_env = no/' /usr/local/etc/php/fpm/php.ini 2>/dev/null || true

RUN mkdir -p /app/bootstrap/cache \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8080"]
