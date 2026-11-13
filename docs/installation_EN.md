# Installation (local development)

Prerequisites: PHP ≥ 8.3 (8.5 recommended), Node 22+, Composer, Docker (for PostgreSQL).

## Option a — `composer create-project` (latest published release)

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
[`self-hosting_EN.md`](self-hosting_EN.md) for the equivalent `git pull`-based
procedure if you cloned the sources instead.

## Option b — from a clone of the sources

```bash
cp .env.example .env         # then uncomment DB_HOST=127.0.0.1 and DB_PORT=5433 ("host dev" mode, see .env.example)
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
