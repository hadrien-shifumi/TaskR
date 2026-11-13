# Installation (développement local)

Prérequis : PHP ≥ 8.3 (8.5 recommandé), Node 22+, Composer, Docker (pour PostgreSQL).

## Option a — `composer create-project` (dernière release publiée)

```bash
composer create-project hadrien-shifumi/taskr
cd taskr
php artisan taskr:install
```

L'assistant (lancé automatiquement par `create-project`) demande où tourne
la base de données (docker embarqué / dev local sur le port 5433 / PostgreSQL
managé externe), le fournisseur d'embeddings de la base de connaissance
(préréglages OpenAI, Ollama, Mistral, ou tout endpoint personnalisé
compatible OpenAI), teste si vous le souhaitez la connexion à la base et au
endpoint d'embeddings, écrit le `.env` (sans toucher aux clés qu'il ne gère
pas), génère l'`APP_KEY`, puis propose les migrations et le build frontend.
Il fixe `KNOWLEDGE_EMBEDDINGS_DIMENSIONS` **avant** la première migration,
comme l'exige la colonne pgvector. Relançable à tout moment
(`php artisan taskr:install`), il est idempotent ; sans terminal interactif,
il se replie sur les valeurs détectées dans le `.env`.

**Mettre à jour une installation `create-project`** : cette installation n'a
pas d'historique `.git` d'où faire un `git pull` (`create-project` télécharge
une archive de release, pas un clone). Lancez plutôt `php artisan
taskr:update` — il vérifie sur GitHub s'il existe une release plus récente,
la télécharge et l'applique sur place (chaque fichier suivi est écrasé ou
ajouté ; `.env`, les données de `storage/`, `vendor/` et `node_modules/` ne
font jamais partie de l'archive, donc ils restent intacts), puis propose de
lancer `composer install`, de reconstruire le frontend et de lancer les
migrations. Ajoutez `--check` pour seulement signaler si une mise à jour est
disponible, ou `--force` pour sauter toutes les confirmations (requis dans
un contexte non interactif, par exemple une tâche cron). Sauvegardez votre
base de données avant de le lancer — voir
[`self-hosting_FR.md`](self-hosting_FR.md) pour la procédure équivalente basée
sur `git pull` si vous avez cloné les sources à la place.

## Option b — depuis un clone des sources

```bash
cp .env.example .env         # puis décommentez DB_HOST=127.0.0.1 et DB_PORT=5433 (mode « host dev », voir .env.example)
docker compose up -d postgres  # PostgreSQL 18 + pgvector exposé sur le port 5433 (voir docker-compose.yml)
composer setup               # installe les dépendances, la clé d'app, les migrations, npm install, le build Vite
composer dev                 # démarre le serveur, la queue, les logs et Vite (php artisan dev)
```

L'application est alors servie sur http://localhost:8000. Pensez à définir
`APP_NAME=Taskr` dans votre `.env`.

Configuration notable (`.env`) :

- `DB_*` : PostgreSQL (port **5433** par défaut avec le fichier compose, pour que les commandes artisan locales tapent dans la même base que les conteneurs). L'extension **pgvector** est requise pour la recherche sémantique de la base de connaissance.
- `KNOWLEDGE_EMBEDDINGS_*` (optionnel) : n'importe quel endpoint
  d'embeddings compatible OpenAI (OpenAI, Ollama, vLLM, LM Studio,
  Mistral…) pour vectoriser les pages de connaissance. Quatre variables :
  `KNOWLEDGE_EMBEDDINGS_BASE_URL`, `KNOWLEDGE_EMBEDDINGS_API_KEY`,
  `KNOWLEDGE_EMBEDDINGS_MODEL` et `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`
  (dimension renvoyée par le modèle, 1024 par défaut) — voir
  « [Choisir son propre fournisseur d'embeddings](#choisir-son-propre-fournisseur-dembeddings) » ci-dessous.

Un déploiement local « prod-like » est également possible (repasser en mode 1 :
recommenter `DB_HOST`/`DB_PORT` dans le `.env`) : `docker compose up -d`
démarre l'image publiée (application Octane/FrankenPHP sur
http://localhost:8080, worker de queue, PostgreSQL/pgvector) ; `docker
compose up -d --build` construit l'image localement pour tester vos propres
modifications.

## Choisir son propre fournisseur d'embeddings

La recherche sémantique de la base de connaissance fonctionne avec
n'importe quel endpoint d'embeddings compatible OpenAI. Renseignez quatre
variables dans le `.env` :

```bash
KNOWLEDGE_EMBEDDINGS_BASE_URL=    # ex. https://api.openai.com/v1
KNOWLEDGE_EMBEDDINGS_API_KEY=
KNOWLEDGE_EMBEDDINGS_MODEL=       # ex. text-embedding-3-small
KNOWLEDGE_EMBEDDINGS_DIMENSIONS=  # dimension renvoyée par le modèle (1024 par défaut)
```

Exemples de fournisseurs :

| Fournisseur        | Endpoint                  | Modèle                  | Dimensions       |
| ------------------ | ------------------------- | ---------------------- | ---------------- |
| OpenAI             | `https://api.openai.com/v1` | `text-embedding-3-small` | 1536           |
| Ollama             | `http://localhost:11434/v1` | `nomic-embed-text`     | 768              |
| vLLM / LM Studio   | `http://localhost:8000/v1`  | votre modèle local       | selon le modèle |
| Mistral            | `https://api.mistral.ai/v1` | `mistral-embed`        | 1024             |

> ⚠️ Fixez `KNOWLEDGE_EMBEDDINGS_DIMENSIONS` **avant** `php artisan migrate` :
> la colonne pgvector `vector(N)` et son index sont dimensionnés à partir de
> cette valeur au moment de la migration. Si la config est en cache, lancez
> `php artisan config:clear` avant de changer la valeur.

Un garde-fou intégré compare la dimension renvoyée par le fournisseur à
celle configurée : en cas d'écart, la vectorisation et la recherche échouent
avec un message explicite (ajuster `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`) plutôt
qu'une erreur pgvector obscure. Sans configuration, la recherche sémantique
se désactive proprement — l'écriture et la navigation restent pleinement
utilisables.

**Changer de modèle ou de dimension sur une instance existante**
(PostgreSQL + pgvector) :

1. `php artisan config:clear`, puis mettez à jour le `.env` (nouveaux
   `KNOWLEDGE_EMBEDDINGS_MODEL` et `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`).
2. Sur la base de données, remplacez `N` par la nouvelle dimension et lancez :

   ```sql
   DROP INDEX knowledge_pages_embedding_ivfflat;
   UPDATE knowledge_pages SET embedding = NULL, embedded_at = NULL;
   ALTER TABLE knowledge_pages ALTER COLUMN embedding TYPE vector(N);
   CREATE INDEX knowledge_pages_embedding_ivfflat
       ON knowledge_pages USING ivfflat (embedding vector_cosine_ops);
   ```

3. Revectorisez toutes les pages : `php artisan knowledge:reembed`.
