<p align="center"><img src="public/assets/taskr-lockup.svg" width="220" alt="TaskR"></p>

# TaskR

**TaskR** is a minimalist, multi-tenant ticket tracker built around the **workspace**: teams create projects and issues, track statuses, time spent and project health — no complex agility patterns, no issue types, no configurable workflow. Steering happens by convention: the workspace imposes its statuses on all of its projects.

TaskR is designed to be driven **by humans (web UI) and AI agents alike** through a dedicated MCP server. Identifiers are opaque ULIDs in URLs (no “TASK-42”), and there are only two roles: `owner` and `member`.

## Features

- **Multi-tenant workspaces** — a user can belong to several workspaces and switch between them; invitations by email (`owner`/`member` role, optional projects), strict data isolation between workspaces.
- **Projects** — minimal container (name, description, target date), archiving, project members with roles, and **computed health**: progress %, overdue issues, hour consumption (logged vs estimated), `on track` / `at risk` / `late` projection.
- **Issues** — title, **Markdown** description, status, priority (`none` / `low` / `high` / `urgent`), free-form labels, **multiple assignees**, due date, hour estimation, **soft hierarchy** (parent/children, any depth, cycles forbidden), **dependencies** between issues, archiving.
- **Statuses** — defined at the workspace level (free name + semantic category `backlog` / `in_progress` / `done` / `cancelled`), reorderable by drag & drop; free status changes, no imposed transitions.
- **Comments** with **@mentions** and related notifications.
- **Time tracking** — hour estimates and time entries (editing/deleting reserved to the author).
- **Issue templates** — pre-fill the issue creation form (no persistent link).
- **Knowledge base** — hierarchical Markdown pages (TipTap editor), trash (soft delete, restore, permanent deletion), **versioning** (history, version comparison, restore) and **semantic search** via pgvector (any OpenAI-compatible embeddings endpoint, e.g. Mistral).
- **Notifications** — mentions page, per-account notification preferences, periodic email digest (queued job).
- **API & MCP** — Sanctum personal tokens and a stateless MCP server on `POST /mcp` (~50 tools) to drive TaskR from an AI agent with the same validations and permissions as the UI.
- **i18n** — interface in French and English, at each user's choice.

## Tech stack

| Layer | Technologies |
|---|---|
| Backend | PHP 8.5, Laravel 13, Laravel Octane (FrankenPHP in worker mode), PostgreSQL + pgvector, Sanctum, [laravel-actions](https://github.com/lorisleiva/laravel-actions) (Actions + Responders), Ziggy |
| Frontend | Inertia v3, React 19, TypeScript, Tailwind CSS v4, shadcn/ui on Base UI, TipTap (Markdown), react-i18next, Storybook |
| Testing | Pest — Unit, Feature and Browser suites (Playwright E2E, desktop + mobile) |
| AI tooling | Laravel Boost (MCP), OpenCode with the `taskr-backend`, `taskr-frontend`, `taskr-issue-dev`, `tdd` skills |
| Quality | Pint |

## Architecture

- **Workspace-based multi-tenancy**: all data belongs to a workspace, scoped by a global scope (`WorkspaceScope`) and a context (`WorkspaceContext`) resolved by middleware; no data from an unjoined workspace is visible, even by direct URL.
- **ULID identifiers** for every resource (workspaces, projects, issues, knowledge pages…).
- **Actions as controllers** (`app/Actions`) with **Responders** (`app/Responders`) for Inertia/redirect responses — no classical business controllers.
- **Stateless MCP** (`routes/ai.php`): `POST /mcp` authenticated by a Sanctum token (no session or cookies), `TaskrServer` exposed to agents.

## Installation (local development)

Prerequisites: PHP ≥ 8.3 (8.5 recommended), Node 22+, Composer, Docker (for PostgreSQL).

**Option a — `composer create-project` (latest published release)**:

```bash
composer create-project hadrien-shifumi/taskr
cd taskr
php artisan taskr:install
```

The wizard (started automatically by `create-project`) asks where the
database runs (bundled docker / local dev on 5433 / external managed
PostgreSQL), the knowledge base embeddings provider (OpenAI, Ollama, Mistral
presets or any OpenAI-compatible custom endpoint), tests the database
connection and the embeddings endpoint if you want, writes the `.env`
(without touching the keys it does not manage), generates the `APP_KEY`, then
offers migrations and the frontend build. It sets
`KNOWLEDGE_EMBEDDINGS_DIMENSIONS` **before** the first migration, as the
pgvector column requires. Re-runnable at any time
(`php artisan taskr:install`), it is idempotent; without an interactive
terminal it falls back to the values detected in the `.env`.

**Updating a `create-project` install**: this install has no `.git` history
to `git pull` from (`create-project` downloads a release archive, not a
clone). Run `php artisan taskr:update` instead — it checks GitHub for a newer
release, downloads and applies it in place (every tracked file is
overwritten or added; `.env`, `storage/` data, `vendor/` and `node_modules/`
are never part of the archive, so they are left untouched), then offers to
run `composer install`, rebuild the frontend and run the migrations. Add
`--check` to only report whether an update is available, or `--force` to
skip every confirmation (required in non-interactive contexts, e.g. a cron
job). Back up your database before running it — see
“[Self-hosting](#self-hosting)” below for the equivalent `git pull`-based
procedure if you cloned the sources instead.

**Option b — from a clone of the sources**:

```bash
cp .env.example .env         # then uncomment DB_HOST=127.0.0.1 and DB_PORT=5433 (“host dev” mode, see .env.example)
docker compose up -d postgres  # PostgreSQL 18 + pgvector exposed on port 5433 (see docker-compose.yml)
composer setup               # install dependencies, app key, migrations, npm install, Vite build
composer dev                 # starts the server, queue, logs and Vite (php artisan dev)
```

The app is then served on http://localhost:8000. Remember to set `APP_NAME=Taskr` in your `.env`.

Notable configuration (`.env`):

- `DB_*`: PostgreSQL (port **5433** by default with the compose file, so local artisan commands hit the same database as the containers). The **pgvector** extension is required for the knowledge base semantic search.
- `KNOWLEDGE_EMBEDDINGS_*` (optional): any OpenAI-compatible embeddings
  endpoint (OpenAI, Ollama, vLLM, LM Studio, Mistral…) for vectorizing
  knowledge pages. Four variables: `KNOWLEDGE_EMBEDDINGS_BASE_URL`,
  `KNOWLEDGE_EMBEDDINGS_API_KEY`, `KNOWLEDGE_EMBEDDINGS_MODEL` and
  `KNOWLEDGE_EMBEDDINGS_DIMENSIONS` (dimension returned by the model, 1024 by
  default) — see “[Bring your own embeddings provider](#bring-your-own-embeddings-provider)” below.

A local “prod-like” deployment is also possible (switch back to mode 1:
re-comment `DB_HOST`/`DB_PORT` in the `.env`): `docker compose up -d` starts
the published image (Octane/FrankenPHP app on http://localhost:8080, queue
worker, PostgreSQL/pgvector); `docker compose up -d --build` builds the image
locally to test your own changes.

## Bring your own embeddings provider

The knowledge base semantic search works with any OpenAI-compatible
embeddings endpoint. Fill four variables in the `.env`:

```bash
KNOWLEDGE_EMBEDDINGS_BASE_URL=    # e.g. https://api.openai.com/v1
KNOWLEDGE_EMBEDDINGS_API_KEY=
KNOWLEDGE_EMBEDDINGS_MODEL=       # e.g. text-embedding-3-small
KNOWLEDGE_EMBEDDINGS_DIMENSIONS=  # dimension returned by the model (1024 by default)
```

Provider examples:

| Provider           | Endpoint                  | Model                  | Dimensions       |
| ------------------ | ------------------------- | ---------------------- | ---------------- |
| OpenAI             | `https://api.openai.com/v1` | `text-embedding-3-small` | 1536           |
| Ollama             | `http://localhost:11434/v1` | `nomic-embed-text`     | 768              |
| vLLM / LM Studio   | `http://localhost:8000/v1`  | your local model       | depends on model |
| Mistral            | `https://api.mistral.ai/v1` | `mistral-embed`        | 1024             |

> ⚠️ Set `KNOWLEDGE_EMBEDDINGS_DIMENSIONS` **before** `php artisan migrate`:
> the pgvector `vector(N)` column and its index are sized from this value at
> migration time. If the config is cached, run `php artisan config:clear`
> before changing the value.

A built-in guard compares the dimension returned by the provider with the
configured one: on mismatch, vectorization and search fail with an explicit
message (adjust `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`) rather than an obscure
pgvector error. Without configuration, semantic search disables itself
cleanly — writing and browsing remain fully usable.

**Changing the model or dimension on an existing instance**
(PostgreSQL + pgvector):

1. `php artisan config:clear`, then update the `.env` (new
   `KNOWLEDGE_EMBEDDINGS_MODEL` and `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`).
2. On the database, replace `N` with the new dimension and run:

   ```sql
   DROP INDEX knowledge_pages_embedding_ivfflat;
   UPDATE knowledge_pages SET embedding = NULL, embedded_at = NULL;
   ALTER TABLE knowledge_pages ALTER COLUMN embedding TYPE vector(N);
   CREATE INDEX knowledge_pages_embedding_ivfflat
       ON knowledge_pages USING ivfflat (embedding vector_cosine_ops);
   ```

3. Re-vectorize every page: `php artisan knowledge:reembed`.

## Testing

```bash
composer test        # Pest: Unit + Feature suites
composer test:e2e    # Browser suite (Playwright, desktop + mobile viewports) — Vite build included
```

The TDD workflow is mandatory in this project (see `AGENTS.md` and `.opencode/skills/tdd`): test plan, red test, implementation, Unit+Feature green before moving on to the Browser suite.

## Driving TaskR from AI agents (MCP)

1. Create a token in **Settings → API tokens** (value shown only once).
2. Expose the `POST /mcp` endpoint to your agent, authenticated with `Authorization: Bearer <token>` (stateless, Sanctum token).

The `Taskr` server provides ~50 tools (workspaces, projects, issues, comments, statuses, labels, time tracking, knowledge, templates…). Start with `list_workspaces`, then pass the target `workspace_id` to every tool. Each tool applies the same validations and permissions as the web UI.

Example client configuration (OpenCode):

```json
{
  "mcp": {
    "servers": {
      "taskr": {
        "type": "remote",
        "url": "https://taskr.example.com/mcp",
        "oauth": false,
        "headers": { "Authorization": "Bearer {env:TASKR_MCP_TOKEN}" }
      }
    }
  }
}
```

## Self-hosting

TaskR is designed to be self-hosted: a multi-stage Docker image (`Dockerfile`,
FrankenPHP 1.13 / PHP 8.5, **Laravel Octane in worker mode**
`octane:frankenphp`, Vite build in a separate stage, `pdo_pgsql`, `intl`,
`bcmath` extensions…) runs in two roles: `web` (Octane, migrations at boot via
`docker/entrypoint.sh`) and `worker` (`queue:work`).

### 1. Docker Compose + prebuilt image (recommended)

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

#### External (managed) database

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

### 2. `composer create-project` (self-hosted, no Docker)

Same installer as “[Installation (local development)](#installation-local-development)”
above — `composer create-project hadrien-shifumi/taskr && cd taskr && php
artisan taskr:install` — configured for a public/production deployment when
the wizard asks (`APP_ENV=production`, `APP_DEBUG=false`, the real
`APP_URL`). Serve it behind Octane (`php artisan octane:start`) or any
PHP-FPM setup, with a reverse proxy doing TLS termination.

Updating: this install has no `.git` history to `git pull` from — run `php
artisan taskr:update` (see “[Updating a `create-project` install](#installation-local-development)”
above). It checks GitHub for a newer release and applies it in place, then
offers to run `composer install`, rebuild the frontend and migrate.

### 3. From the sources

PHP ≥ 8.3, Node 22, PostgreSQL + pgvector: follow
“[Installation (local development)](#installation-local-development)”
above — `composer setup`, then serve with `composer dev` or the local image
(`docker compose up --build`).

## Documentation & conventions

- [`AGENTS.md`](AGENTS.md) — project conventions (mandatory TDD, `issue-dev` workflow for every issue: research → plan → implementation → knowledge base review, managed in TaskR itself).
- [`docs/design-brief.md`](docs/design-brief.md) — product brief (concepts, business rules, screens).
- [`docs/design-system/`](docs/design-system) — design system (tokens, components, patterns) and [`docs/maquettes/`](docs/maquettes) — HTML mockups.
- `.opencode/skills/` — the `taskr-backend`, `taskr-frontend`, `taskr-issue-dev`, `tdd` skills.

## Repository

[github.com/hadrien-shifumi/TaskR](https://github.com/hadrien-shifumi/TaskR)
