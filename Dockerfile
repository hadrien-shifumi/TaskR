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
# `1.13` floats on purpose: it follows FrankenPHP's patch releases (security
# fixes included) and every build re-resolves it. release.yml puts the
# version a build resolved to on its record, and smoke-tests it before
# publishing. A digest pin would freeze those fixes out until bumped by hand.
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

# The build context feeds the stages above too, so the frontend tooling the
# assets stage compiles from is left out here instead: the runtime never
# reads it (the rest is kept out of the context by .dockerignore).
COPY --exclude=package-lock.json \
     --exclude=tsconfig.json \
     --exclude=vite.config.js \
     . /app

COPY --chmod=755 docker/entrypoint.sh docker/preflight.sh /usr/local/bin/
COPY docker/php.ini /usr/local/etc/php/conf.d/99-taskr.ini

RUN sed -i 's/^;clear_env = no/clear_env = no/' /usr/local/etc/php/fpm/php.ini 2>/dev/null || true

RUN mkdir -p /app/bootstrap/cache \
    && chown -R www-data:www-data /app/storage /app/bootstrap/cache

# Caddy's data directory: the embedded Mercure hub keeps its subscriptions
# database there (Bolt, $XDG_DATA_HOME/caddy/mercure.db). The base image
# points it at /data, which no deployment mounts — the storage volume is the
# one that survives container updates.
ENV XDG_DATA_HOME=/app/storage

EXPOSE 8080

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php", "artisan", "octane:frankenphp", "--host=0.0.0.0", "--port=8080"]
