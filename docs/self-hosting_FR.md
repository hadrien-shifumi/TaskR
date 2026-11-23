# Auto-hébergement

TaskR est conçu pour être auto-hébergé : une image Docker multi-étapes
(`Dockerfile`, FrankenPHP 1.13 / PHP 8.5, **Laravel Octane en mode worker**
`octane:frankenphp`, build Vite dans une étape séparée, extensions
`pdo_pgsql`, `intl`, `bcmath`…) tourne sous deux rôles : `web` (Octane,
migrations au démarrage via `docker/entrypoint.sh`) et `worker`
(`queue:work`).

## 1. Docker Compose + image prête à l'emploi (recommandé)

Chaque tag de version (`v*`) est construit par la CI en image multi-arch
`linux/amd64` + `linux/arm64` et publiée sur GHCR :
[`ghcr.io/hadrien-shifumi/taskr`](https://github.com/hadrien-shifumi/TaskR/pkgs/container/taskr).

```bash
git clone https://github.com/hadrien-shifumi/TaskR.git && cd TaskR
cp .env.example .env

# Éditez le .env :
#   APP_KEY=base64:…     # openssl rand -base64 32
#   TASKR_VERSION=0.1.2  # épingler une version plutôt que latest
#   Si exposé publiquement : APP_ENV=production, APP_DEBUG=false,
#   APP_URL=https://taskr.example.com, DB_PASSWORD dédié (avant le premier démarrage)

docker compose up -d
```

Remarque : générez l'`APP_KEY` avec `openssl rand -base64 32` plutôt qu'avec
`docker compose run --rm app php artisan key:generate` — cette commande
écrit la clé à l'intérieur du conteneur éphémère (`.env` n'y est pas monté),
elle serait donc perdue.

Configurez aussi l'envoi des mails (reset de mot de passe, invitations,
digests) avant une exposition publique : `MAIL_MAILER=smtp` plus
`MAIL_HOST`/`MAIL_PORT`/`MAIL_USERNAME`/`MAIL_PASSWORD`/
`MAIL_FROM_ADDRESS`/`MAIL_FROM_NAME` dans le `.env`. Par défaut,
`MAIL_MAILER=log` écrit les mails dans les logs sans les délivrer. Passer
par `php artisan taskr:install` (section 2 ci-dessous) pose la question et
teste l'envoi pour vous.

L'application répond sur http://localhost:8080 (rôles `web` + `worker` +
PostgreSQL 18/pgvector embarqué, actif par défaut via
`COMPOSE_PROFILES=bundled-db`). Les données persistent dans le volume
`taskr-pgdata` et le montage `./storage`. PostgreSQL n'est exposé que sur
`127.0.0.1:5433` (pour les commandes artisan lancées depuis l'hôte) ; ses
identifiants sont lus dans le `.env`
(`DB_USERNAME`/`DB_PASSWORD`/`DB_DATABASE`, utilisés seulement au premier
démarrage du volume). Par défaut, compose suit `latest` — épinglez une
version exacte avec `TASKR_VERSION=0.1.2` dans le `.env`.

Mise à jour :

```bash
docker compose exec postgres pg_dump -U postgres taskr > backup-$(date +%F).sql  # sauvegarde recommandée
git pull                   # docker-compose.yml / .env.example ne vivent pas dans l'image GHCR
docker compose pull
docker compose up -d   # les migrations tournent au démarrage du rôle web
```

> Si vous avez épinglé `TASKR_VERSION` (recommandé en production), c'est le
> vrai geste de mise à jour : faites-le pointer sur la nouvelle version dans
> le `.env` avant le `pull`, sinon `docker compose pull` retélécharge
> l'ancienne.

> Pour être notifié des nouvelles versions, activez le profil `diun`
> (`COMPOSE_PROFILES=bundled-db,diun` dans le `.env`, puis `docker compose up
> -d`) : [Diun](https://crazymax.dev/diun/) vérifie GHCR toutes les 6 heures
> et signale chaque nouveau tag stable — dans les logs (`docker compose logs
> diun`) ou via n'importe quel canal `DIUN_NOTIF_*` défini dans le `.env`
> (Telegram, ntfy, mail…). Il ne déploie jamais rien : la mise à jour reste
> le geste explicite ci-dessus, migrations comprises.

> Si exposé publiquement : `APP_ENV=production`, `APP_DEBUG=false`, un
> `APP_URL` correct, un `DB_PASSWORD` dédié avant le premier démarrage, et un
> reverse proxy faisant la terminaison TLS devant le port 8080 (l'image sert
> du HTTP).

> La **section Chatbot (assistant IA in-app) est masquée par défaut** —
> `TASKR_FEATURE_CHAT=false` : pas d'entrée de navigation, les pages
> `/chatbot` répondent 404. Posez `TASKR_FEATURE_CHAT=true` dans le `.env`
> pour l'exposer ; l'écran Réglages IA reste utilisable dans tous les cas.

### Hub Mercure (temps réel)

Le déploiement docker embarque un **hub Mercure** : le serveur FrankenPHP
d'Octane dérive sa configuration de la connexion de diffusion
(`config/broadcasting.php`, créée app-level pour figer le contrat) quand
`BROADCAST_CONNECTION=mercure` — le hub répond alors sur
`/.well-known/mercure`, même domaine que l'application (aucun port
supplémentaire n'est ouvert, tout reste dans le conteneur web).

- **Variables d'environnement** : `MERCURE_JWT_SECRET` (générée avec
  `openssl rand -hex 32` — hexadécimal nu, 64 caractères, placée dans le
  `.env` avant le premier démarrage) ; `MERCURE_URL` / `MERCURE_PUBLIC_URL`
  optionnelles quand le hub vit sur un autre domaine ; `MERCURE_ASYNC`
  (publication asynchrone, défaut : synchronisée).
- **Persistance** : la base d'abonnements du hub est un fichier BoltDB —
  configurée sur le volume déjà monté du serveur
  (`config/octane.php`, transport `bolt`, chemin `/app/storage/mercure.db`),
  elle survit aux mises à jour d'image.
- **Pièges connus** : le hub est exclu de la compression Caddy (le stub
  Caddyfile d'Octane), les logs d'abonnement sont silencieux, et le
  paramètre `authorization` des requêtes d'abonnement est reDACTé.
- **Vérification manuelle** (le hub live ne se prouve pas en Pest —
  FrankenPHP ne tourne pas sous `artisan serve`) :

```bash
docker compose up -d
curl -i $APP_URL/.well-known/mercure
# HTTP/1.1 401 Unauthorized  (refus des abonnements anonymes : le hub répond)
curl -i -H "Cookie: MERCURE_SUBSCRIBE_TOKEN=<jwt>" $APP_URL/.well-known/mercure
# HTTP/1.1 200 OK  (text/event-stream — l'abonnement est établi)
```

### Base de données externe (managée)

Pour pointer vers un PostgreSQL externe ou managé (Supabase, Neon,
Scaleway…) plutôt que la base embarquée :

1. Dans le `.env`, commentez `COMPOSE_PROFILES=bundled-db` — le service
   `postgres` disparaît de la stack.
2. Renseignez la connexion : soit les variables distinctes
   (`DB_HOST`/`DB_PORT`/`DB_USERNAME`/`DB_PASSWORD`), soit une ligne unique
   `DB_URL=postgres://user:password@host:5432/taskr?sslmode=require`
   (`DB_SSLMODE=require` fonctionne aussi, `prefer` par défaut).
3. Prérequis : PostgreSQL **avec l'extension pgvector**. Lancez
   `CREATE EXTENSION IF NOT EXISTS vector;` sur la base cible avant le
   premier démarrage — la migration créant la colonne `embedding
   vector(N)` (dimension lue depuis `KNOWLEDGE_EMBEDDINGS_DIMENSIONS`, 1024
   par défaut, au moment de la migration) tourne automatiquement au
   démarrage du rôle web et échoue sans l'extension. Cette dimension est la
   taille de stockage globale de l'instance : tout fournisseur d'embeddings
   — le global ou celui propre à un espace — doit produire des vecteurs de
   exactement cette taille.
4. `docker compose up -d` : l'application démarre sans attendre la base
   embarquée, les migrations tournent comme d'habitude. Les sauvegardes sont
   alors gérées côté fournisseur (le `pg_dump` de compose ne s'applique qu'à
   la base embarquée).

## 2. `composer create-project` (auto-hébergé, sans Docker)

Même installateur que [`installation_FR.md`](installation_FR.md) ci-dessus —
`composer create-project hadrien-shifumi/taskr && cd taskr && php artisan
taskr:install` — configuré pour un déploiement public/production quand
l'assistant le demande (`APP_ENV=production`, `APP_DEBUG=false`, le vrai
`APP_URL`), avec configuration de l'envoi des mails (SMTP, avec test
d'envoi) au passage. Servez-le derrière Octane (`php artisan octane:start`) ou
n'importe quel setup PHP-FPM, avec un reverse proxy faisant la terminaison
TLS.

Mise à jour : cette installation n'a pas d'historique `.git` d'où faire un
`git pull` — lancez `php artisan taskr:update` (voir
[`installation_FR.md`](installation_FR.md)). Il vérifie sur GitHub s'il
existe une release plus récente et l'applique sur place, puis propose de
lancer `composer install`, de reconstruire le frontend et de migrer.

## 3. Depuis les sources

PHP ≥ 8.3, Node 22, PostgreSQL + pgvector : suivez
[`installation_FR.md`](installation_FR.md) — `composer setup`, puis servez
avec `composer dev` ou l'image locale (`docker compose up --build`).
