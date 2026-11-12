<p align="center"><img src="public/assets/taskr-lockup.svg" width="220" alt="TaskR"></p>

# TaskR

**TaskR** est un tracker de tickets multi-tenant et minimaliste, organisé autour du **workspace** : des équipes y créent des projets, des issues, suivent les statuts, le temps passé et la santé des projets — sans sprints, sans types d'issue, sans workflow configurable. Le pilotage se fait par convention : le workspace impose ses statuts à tous ses projets.

TaskR est conçu pour être piloté **autant par des humains (UI web) que par des agents IA** via un serveur MCP dédié. Les identifiants sont des ULID opaques dans les URLs (pas de « TASK-42 »), et il n'existe que deux rôles : `owner` et `member`.

## Fonctionnalités

- **Workspaces multi-tenants** — un utilisateur peut appartenir à plusieurs workspaces et basculer entre eux ; invitations par email (rôle `owner`/`member`, projets optionnels), isolation stricte des données entre workspaces.
- **Projets** — conteneur minimal (nom, description, date cible), archivage, membres de projet avec rôles, et **santé calculée** : avancement %, issues en retard, consommation des heures (loggué vs estimé), projection `on track` / `at risk` / `late`.
- **Issues** — titre, description **Markdown**, statut, priorité (`none` / `low` / `high` / `urgent`), labels libres, **plusieurs assignés**, due date, estimation en heures, **hiérarchie molle** (parent/enfants, profondeur libre, cycles interdits), **dépendances** entre issues, archivage.
- **Statuts** — définis au niveau du workspace (nom libre + catégorie sémantique `backlog` / `in_progress` / `done` / `cancelled`), réordonnables par drag & drop ; changement de statut libre, sans transition imposée.
- **Commentaires** avec **@mentions** et notifications associées.
- **Time tracking** — estimations d'heures et saisies de temps (édition/suppression réservées à l'auteur).
- **Templates d'issues** — pré-remplissage de la création d'issue (sans lien persistant).
- **Base de connaissances** — pages hiérarchiques en Markdown (éditeur TipTap), corbeille (soft delete, restauration, suppression définitive), **versioning** (historique, comparaison de versions, restauration) et **recherche sémantique** via pgvector (endpoint d'embeddings compatible OpenAI, ex. Mistral).
- **Notifications** — page des mentions, préférences de notification par compte, digest email périodique (job en queue).
- **API & MCP** — tokens personnels Sanctum et serveur MCP stateless sur `POST /mcp` (~50 outils) pour piloter TaskR depuis un agent IA avec les mêmes validations et permissions que l'UI.
- **i18n** — interface en français et en anglais, au choix de chaque utilisateur.

## Stack technique

| Couche | Technologies |
|---|---|
| Backend | PHP 8.5, Laravel 13, Laravel Octane (FrankenPHP en mode worker), PostgreSQL + pgvector, Sanctum, [laravel-actions](https://github.com/lorisleiva/laravel-actions) (Actions + Responders), Ziggy |
| Frontend | Inertia v3, React 19, TypeScript, Tailwind CSS v4, shadcn/ui sur Base UI, TipTap (Markdown), react-i18next, Storybook |
| Tests | Pest — suites Unit, Feature et Browser (E2E Playwright, desktop + mobile) |
| Outils IA | Laravel Boost (MCP), OpenCode avec skills `taskr-backend`, `taskr-frontend`, `taskr-issue-dev`, `tdd` |
| Qualité | Pint |

## Architecture

- **Multi-tenancy par workspace** : toutes les données appartiennent à un workspace, scopées par un global scope (`WorkspaceScope`) et un contexte (`WorkspaceContext`) résolu par middleware ; aucune donnée d'un workspace non rejoint n'est visible, même par URL directe.
- **Identifiants ULID** pour toutes les ressources (workspaces, projets, issues, pages de connaissance…).
- **Actions comme controllers** (`app/Actions`) avec **Responders** (`app/Responders`) pour les réponses Inertia/redirect — pas de controllers classiques métier.
- **MCP stateless** (`routes/ai.php`) : `POST /mcp` authentifié par token Sanctum (pas de session ni cookies), serveur `TaskrServer` exposé aux agents.

## Installation (développement local)

Prérequis : PHP ≥ 8.3 (8.5 recommandé), Node 22+, Composer, Docker (pour PostgreSQL).

```bash
composer setup        # install dépendances, .env, clé d'app, migrations, npm install, build Vite
docker compose up -d  # PostgreSQL 18 + pgvector exposé sur le port 5433 (voir docker-compose.yml)
composer dev          # lance serveur, queue, logs et Vite (php artisan dev)
```

L'application est alors sur http://localhost:8000. Pense à définir `APP_NAME=Taskr` dans ton `.env`.

Configuration notable (`.env`) :

- `DB_*` : PostgreSQL (port **5433** par défaut avec le compose, pour que les commandes artisan locales tapent la même base que les conteneurs). L'extension **pgvector** est requise pour la recherche sémantique de la base de connaissances.
- `KNOWLEDGE_EMBEDDINGS_*` (optionnel) : endpoint d'embeddings compatible OpenAI (ex. `https://api.mistral.ai/v1`, modèle `mistral-embed`) pour la vectorisation des pages de connaissance.

Un déploiement « prod-like » local est aussi possible avec les mêmes images que la production :

```bash
docker compose up --build
# app (Octane/FrankenPHP) sur http://localhost:8080, worker queue:work, PostgreSQL pgvector
```

## Tests

```bash
composer test        # Pest : suites Unit + Feature
composer test:e2e    # suite Browser (Playwright, viewports desktop + mobile) — build Vite inclus
```

Le workflow TDD est obligatoire dans ce projet (voir `AGENTS.md` et `.opencode/skills/tdd`) : plan de tests, test rouge, implémentation, Unit+Feature au vert avant de passer à la suite Browser.

## Pilotage par agents IA (MCP)

1. Crée un token dans **Settings → API tokens** (valeur affichée une seule fois).
2. Expose l'endpoint `POST /mcp` à ton agent, authentifié par `Authorization: Bearer <token>` (stateless, token Sanctum).

Le serveur `Taskr` fournit ~50 outils (workspaces, projets, issues, commentaires, statuts, labels, time tracking, connaissance, templates…). Commencer par `list_workspaces`, puis passer le `workspace_id` cible à chaque outil. Chaque outil applique les mêmes validations et permissions que l'UI web.

Exemple de configuration client (OpenCode) :

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

## Auto-hébergement

TaskR est conçu pour être auto-hébergé. L'image Docker multi-stage (`Dockerfile`,
FrankenPHP 1.13 / PHP 8.5, **Laravel Octane en mode worker** `octane:frankenphp`,
build Vite en stage séparé, extensions `pdo_pgsql`, `intl`, `bcmath`…) tourne en
deux rôles : `web` (Octane, migrations au boot via `docker/entrypoint.sh`,
healthcheck sur `/up`) et `worker` (`queue:work`).

Le `docker-compose.yml` fourni lance l'app, le worker et PostgreSQL/pgvector :

```bash
cp .env.example .env
docker compose run --rm app php artisan key:generate   # remplit APP_KEY
docker compose up -d
```

> L'instance de l'auteur, elle, est déployée sur Scaleway avec **Kamal 2**
> (proxy Traefik en terminaison TLS, registry privé, PostgreSQL managé avec
> pgvector) et provisionnée avec Ansible. Cette couche déploiement/opérations
> vit dans un dépôt séparé privé (`taskr-infra`) — elle ne fait pas partie du
> produit.

## Documentation & conventions

- [`AGENTS.md`](AGENTS.md) — conventions du projet (TDD obligatoire, workflow `issue-dev` pour chaque issue : recherche → plan → implémentation → revue de la base de connaissances, géré dans TaskR lui-même).
- [`docs/design-brief.md`](docs/design-brief.md) — brief produit (concepts, règles métier, écrans).
- [`docs/design-system/`](docs/design-system) — design system (tokens, composants, patterns) et [`docs/maquettes/`](docs/maquettes) — maquettes HTML.
- `.opencode/skills/` — skills `taskr-backend`, `taskr-frontend`, `taskr-issue-dev`, `tdd`.

## Dépôt

[github.com/hadrien-shifumi/TaskR](https://github.com/hadrien-shifumi/TaskR)
