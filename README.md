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
cp .env.example .env         # puis décommenter DB_HOST=127.0.0.1 et DB_PORT=5433 (mode « dev hôte », voir .env.example)
docker compose up -d postgres  # PostgreSQL 18 + pgvector exposé sur le port 5433 (voir docker-compose.yml)
composer setup               # install dépendances, clé d'app, migrations, npm install, build Vite
composer dev                 # lance serveur, queue, logs et Vite (php artisan dev)
```

L'application est alors sur http://localhost:8000. Pense à définir `APP_NAME=Taskr` dans ton `.env`.

Configuration notable (`.env`) :

- `DB_*` : PostgreSQL (port **5433** par défaut avec le compose, pour que les commandes artisan locales tapent la même base que les conteneurs). L'extension **pgvector** est requise pour la recherche sémantique de la base de connaissances.
- `KNOWLEDGE_EMBEDDINGS_*` (optionnel) : endpoint d'embeddings compatible OpenAI (ex. `https://api.mistral.ai/v1`, modèle `mistral-embed`) pour la vectorisation des pages de connaissance.

Un déploiement « prod-like » local est aussi possible (repasse en mode 1 :
recommente `DB_HOST`/`DB_PORT` dans le `.env`) : `docker compose up -d`
démarre l'image publiée (app Octane/FrankenPHP sur http://localhost:8080, worker
de queue, PostgreSQL/pgvector) ; `docker compose up -d --build` construit
l'image localement pour tester tes propres changements.

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

TaskR est conçu pour être auto-hébergé : une image Docker multi-stage
(`Dockerfile`, FrankenPHP 1.13 / PHP 8.5, **Laravel Octane en mode worker**
`octane:frankenphp`, build Vite en stage séparé, extensions `pdo_pgsql`,
`intl`, `bcmath`…) tourne en deux rôles : `web` (Octane, migrations au boot via
`docker/entrypoint.sh`) et `worker` (`queue:work`).

### 1. Docker Compose + image pré-construite (recommandé)

Chaque tag de version (`v*`) est construit par la CI en image multi-arch
`linux/amd64` + `linux/arm64` et publié sur GHCR :
[`ghcr.io/hadrien-shifumi/taskr`](https://github.com/hadrien-shifumi/TaskR/pkgs/container/taskr).

```bash
git clone https://github.com/hadrien-shifumi/TaskR.git && cd TaskR
cp .env.example .env

# Éditer .env :
#   APP_KEY=base64:…     # openssl rand -base64 32
#   TASKR_VERSION=1.2.0  # épingler une version plutôt que latest
#   En exposition publique : APP_ENV=production, APP_DEBUG=false,
#   APP_URL=https://taskr.example.com, DB_PASSWORD dédié (avant le 1er boot)

docker compose up -d
```

Note : générer `APP_KEY` avec `openssl rand -base64 32` plutôt que
`docker compose run --rm app php artisan key:generate` — la commande écrit la
clé dans le conteneur éphémère (`.env` n'y est pas monté), elle serait perdue.

L'app répond sur http://localhost:8080 (rôles `web` + `worker` +
PostgreSQL 18/pgvector embarqué, actif par défaut via
`COMPOSE_PROFILES=bundled-db`). Les données persistent dans le volume
`taskr-pgdata` et le montage `./storage`. PostgreSQL n'est exposé que sur
`127.0.0.1:5433` (pour les commandes artisan lancées depuis l'hôte) ; ses
identifiants sont lus depuis `.env` (`DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE`,
utilisés seulement au premier boot du volume). Par défaut le compose suit
`latest` — épingle une version précise avec `TASKR_VERSION=1.2.0` dans le
`.env`.

Mise à jour :

```bash
docker compose exec postgres pg_dump -U postgres taskr > backup-$(date +%F).sql  # sauvegarde conseillée
git pull                   # docker-compose.yml / .env.example ne vivent pas dans l'image GHCR
docker compose pull
docker compose up -d   # les migrations s'exécutent au démarrage du rôle web
```

> Si tu as épinglé `TASKR_VERSION` (recommandé en production), c'est lui le
> vrai geste de mise à jour : passe-le à la nouvelle version dans le `.env`
> avant le `pull`, sinon `docker compose pull` retélécharge l'ancienne.

> En exposition publique : `APP_ENV=production`, `APP_DEBUG=false`, un `APP_URL`
> correct, un `DB_PASSWORD` dédié avant le premier boot, et un reverse proxy en
> terminaison TLS devant le port 8080 (l'image sert en HTTP).

#### Base de données externe (managée)

Pour pointer sur un PostgreSQL externe ou managé (Supabase, Neon, Scaleway…)
plutôt que la base embarquée :

1. Dans le `.env`, commente `COMPOSE_PROFILES=bundled-db` — le service
   `postgres` disparaît de la stack.
2. Renseigne la connexion : soit les variables distinctes
   (`DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`), soit une seule ligne
   `DB_URL=postgres://user:password@host:5432/taskr?sslmode=require`
   (`DB_SSLMODE=require` fonctionne aussi, `prefer` par défaut).
3. Prérequis : PostgreSQL **avec l'extension pgvector**. Exécute
   `CREATE EXTENSION IF NOT EXISTS vector;` sur la base cible avant le premier
   démarrage — la migration qui crée la colonne `embedding vector(1024)` tourne
   automatiquement au boot du rôle web et échoue sans l'extension.
4. `docker compose up -d` : l'app démarre sans attendre la base embarquée, les
   migrations s'exécutent comme d'habitude. Les sauvegardes se gèrent alors
   côté fournisseur (le `pg_dump` via compose ne s'applique qu'à la base
   embarquée).

### 2. Kamal 2 (VPS)

Le `Dockerfile` se construit tel quel avec Kamal, ou le déploiement peut
consommer directement l'image GHCR (`image:
ghcr.io/hadrien-shifumi/taskr:1.2.0` dans `config/deploy.yml`). L'instance de
l'auteur, déployée sur Scaleway avec **Kamal 2** (proxy Traefik en terminaison
TLS, PostgreSQL managé avec pgvector) et provisionnée avec Ansible, vit dans un
dépôt séparé privé (`taskr-infra`) — cette couche opérations ne fait pas partie
du produit.

### 3. Depuis les sources

PHP ≥ 8.3, Node 22, PostgreSQL + pgvector : suivre
« [Installation (développement local)](#installation-développement-local) »
ci-dessus — `composer setup`, puis servir avec `composer dev` ou l'image locale
(`docker compose up --build`).

## Documentation & conventions

- [`AGENTS.md`](AGENTS.md) — conventions du projet (TDD obligatoire, workflow `issue-dev` pour chaque issue : recherche → plan → implémentation → revue de la base de connaissances, géré dans TaskR lui-même).
- [`docs/design-brief.md`](docs/design-brief.md) — brief produit (concepts, règles métier, écrans).
- [`docs/design-system/`](docs/design-system) — design system (tokens, composants, patterns) et [`docs/maquettes/`](docs/maquettes) — maquettes HTML.
- `.opencode/skills/` — skills `taskr-backend`, `taskr-frontend`, `taskr-issue-dev`, `tdd`.

## Dépôt

[github.com/hadrien-shifumi/TaskR](https://github.com/hadrien-shifumi/TaskR)
