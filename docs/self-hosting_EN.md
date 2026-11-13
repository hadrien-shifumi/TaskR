# Self-hosting

TaskR is designed to be self-hosted: a multi-stage Docker image (`Dockerfile`,
FrankenPHP 1.13 / PHP 8.5, **Laravel Octane in worker mode**
`octane:frankenphp`, Vite build in a separate stage, `pdo_pgsql`, `intl`,
`bcmath` extensions…) runs in two roles: `web` (Octane, migrations at boot via
`docker/entrypoint.sh`) and `worker` (`queue:work`).

## 1. Docker Compose + prebuilt image (recommended)

Every version tag (`v*`) is built by CI as a multi-arch `linux/amd64` +
`linux/arm64` image and published on GHCR:
[`ghcr.io/hadrien-shifumi/taskr`](https://github.com/hadrien-shifumi/TaskR/pkgs/container/taskr).

```bash
git clone https://github.com/hadrien-shifumi/TaskR.git && cd TaskR
cp .env.example .env

# Edit .env:
#   APP_KEY=base64:…     # openssl rand -base64 32
#   TASKR_VERSION=0.1.2  # pin a version instead of latest
#   When publicly exposed: APP_ENV=production, APP_DEBUG=false,
#   APP_URL=https://taskr.example.com, dedicated DB_PASSWORD (before first boot)

docker compose up -d
```

Note: generate `APP_KEY` with `openssl rand -base64 32` rather than
`docker compose run --rm app php artisan key:generate` — that command writes
the key inside the ephemeral container (`.env` is not mounted there), so it
would be lost.

The app answers on http://localhost:8080 (`web` + `worker` roles + bundled
PostgreSQL 18/pgvector, active by default via `COMPOSE_PROFILES=bundled-db`).
Data persists in the `taskr-pgdata` volume and the `./storage` bind mount.
PostgreSQL is only exposed on `127.0.0.1:5433` (for artisan commands run from
the host); its credentials are read from `.env`
(`DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE`, only used at the volume's first
boot). By default compose tracks `latest` — pin an exact version with
`TASKR_VERSION=0.1.2` in the `.env`.

Updating:

```bash
docker compose exec postgres pg_dump -U postgres taskr > backup-$(date +%F).sql  # backup recommended
git pull                   # docker-compose.yml / .env.example do not live in the GHCR image
docker compose pull
docker compose up -d   # migrations run at the web role's startup
```

> If you pinned `TASKR_VERSION` (recommended in production), that is the real
> update gesture: bump it to the new version in the `.env` before the
> `pull`, otherwise `docker compose pull` re-downloads the old one.

> To be notified of new versions, enable the `diun` profile
> (`COMPOSE_PROFILES=bundled-db,diun` in the `.env`, then
> `docker compose up -d`): [Diun](https://crazymax.dev/diun/) checks GHCR
> every 6 hours and reports every new stable tag — in the logs
> (`docker compose logs diun`) or through any `DIUN_NOTIF_*` channel set in
> the `.env` (Telegram, ntfy, mail…). It never deploys anything: updating
> remains the explicit gesture above, migrations included.

> When publicly exposed: `APP_ENV=production`, `APP_DEBUG=false`, a correct
> `APP_URL`, a dedicated `DB_PASSWORD` before the first boot, and a reverse
> proxy doing TLS termination in front of port 8080 (the image serves HTTP).

### External (managed) database

To point at an external or managed PostgreSQL (Supabase, Neon, Scaleway…)
instead of the bundled database:

1. In the `.env`, comment out `COMPOSE_PROFILES=bundled-db` — the `postgres`
   service disappears from the stack.
2. Fill in the connection: either the distinct variables
   (`DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`), or a single line
   `DB_URL=postgres://user:password@host:5432/taskr?sslmode=require`
   (`DB_SSLMODE=require` works too, `prefer` by default).
3. Prerequisite: PostgreSQL **with the pgvector extension**. Run
   `CREATE EXTENSION IF NOT EXISTS vector;` on the target database before the
   first start — the migration creating the `embedding vector(N)` column
   (dimension read from `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`, 1024 by default,
   at migration time) runs automatically at the web role's boot and fails
   without the extension.
4. `docker compose up -d`: the app starts without waiting for the bundled
   database, migrations run as usual. Backups are then managed provider-side
   (the compose `pg_dump` only applies to the bundled database).

## 2. `composer create-project` (self-hosted, no Docker)

Same installer as [`installation_EN.md`](installation_EN.md) above —
`composer create-project hadrien-shifumi/taskr && cd taskr && php artisan
taskr:install` — configured for a public/production deployment when the
wizard asks (`APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL`).
Serve it behind Octane (`php artisan octane:start`) or any PHP-FPM setup,
with a reverse proxy doing TLS termination.

Updating: this install has no `.git` history to `git pull` from — run `php
artisan taskr:update` (see [`installation_EN.md`](installation_EN.md)). It checks
GitHub for a newer release and applies it in place, then offers to run
`composer install`, rebuild the frontend and migrate.

## 3. From the sources

PHP ≥ 8.3, Node 22, PostgreSQL + pgvector: follow
[`installation_EN.md`](installation_EN.md) — `composer setup`, then serve with
`composer dev` or the local image (`docker compose up --build`).
