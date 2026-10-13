## Context

Dépôt vierge. Taskr sera un SaaS multi-tenant léger de gestion de tickets, consommé à la fois par des humains (UI web) et par des agents IA (serveur MCP, change ultérieure). Cette change pose le socle : Laravel + Inertia + React, workspaces, issues à hiérarchie molle, statuts custom, commentaires.

Décisions produit déjà prises pendant l'exploration :

- Hiérarchie molle : une seule entité `issue` avec `parent_id` (pas de types Epic/Story/Task distincts).
- Statuts custom par workspace, chacun rattaché à une catégorie sémantique fixe (`backlog` / `in_progress` / `done` / `cancelled`).
- Priorité : 4 niveaux fixes. Assignés multiples. Découpage en 5 changes verticales : `foundation` → `mcp-access` → `templates` → `time-tracking` → `project-health`.

## Goals / Non-Goals

**Goals:**

- Un utilisateur peut créer un workspace, inviter des membres, créer des projets et des issues hiérarchisées, gérer les statuts, commenter — le tout isolé par tenant.
- Une base de code où la logique métier vit dans des classes réutilisables (actions/services), directement réutilisables plus tard par la couche MCP sans dupliquer les contrôleurs.
- Scoping multi-tenant inaliénable : impossible d'accéder à des données sans contexte workspace valide.

**Non-Goals:**

- Accès MCP, clés API, agents (change `mcp-access`).
- Templates d'issues (change `templates`).
- Estimates, time entries, due dates de projet, santé/avancement (changes `time-tracking`, `project-health`).
- Permissions granulaires, workflows, sprints, notifications.

## Decisions

### ADR-1 : Stack — Laravel 12 + Inertia 2 (React) + Tailwind + shadcn/ui

**Décision** : monolithe Laravel avec Inertia (mode SPA), React 19 **en TypeScript**, Tailwind, et la bibliothèque de composants **shadcn/ui** (composants copiés dans `resources/js/components/ui`, basés sur Radix UI).

**Alternatives** : Next.js + API séparée (plus de glue à maintenir, auth plus lourde) ; Hono + SPA (moins de conventions, l'utilisateur veut Laravel) ; autre bibliothèque de composants type MUI/Chakra (style imposé, moins taillable) ; composants 100 % maison (coût de design non justifié).

**Justification** : conventions fortes = moins de décisions à prendre ; auth, validation, Eloquent couvrent 80 % du socle ; Inertia évite de construire une API REST ET un client — l'API REST dédiée aux agents viendra avec la change MCP si nécessaire, en réutilisant les mêmes actions. shadcn/ui donne une UI soignée et personnalisable sans lock-in : les composants vivent dans le dépôt, modifiables librement, et n'introduisent qu'une dépendance directe à Tailwind + Radix.

### ADR-2 : Multi-tenancy — colonne `workspace_id` + global scope

**Décision** : single database, chaque table métier porte `workspace_id`, un Eloquent global scope force le filtrage, une policy par modèle vérifie l'appartenance.

**Alternatives** : database-per-tenant (migrations multi-BD, backups complexes — overkill pour l'onboarding de clients freelances) ; Postgres RLS (dépend forte SGBD, moins portable).

**Justification** : le plus simple qui isole correctement. Le risque de fuite inter-tenant est couvert par le global scope appliqué automatiquement + tests dédiés.

### ADR-3 : SGBD — PostgreSQL

**Décision** : PostgreSQL (version récente), en dev comme en prod.

**Justification** : matrice d'index partiels/GIN utile plus tard (recherche full-text des issues, exclusions de contrainte pour les dates), standard de facto pour un SaaS. MySQL n'apporte rien ici.

### ADR-4 : Hiérarchie des issues — `parent_id` auto-référencé, profondeur libre

**Décision** : `issues.parent_id` nullable FK vers `issues`. Pas de colonne `type`. Profondeur non plafonnée en base ; l'UI peut afficher 2 niveaux (parent/enfants) sans interdire plus.

**Alternatives** : closure table / materialized path (complexité prématurée — les requêtes utiles sont "enfants directs" et "sous-arbre", supportées par récursion simple ou `WITH RECURSIVE` si besoin).

**Risque** : cycles (A parent de B parent de A) → contrainte applicative à l'assignation du parent (validation : le parent existe, même workspace, et l'insertion ne crée pas de cycle).

### ADR-5 : Statuts — table `statuses` par workspace + enum catégorie

**Décision** : table `statuses` (`workspace_id`, `name`, `category`), `category` en enum PHP app-side + check contrainte SQL. Un workspace démarre avec un jeu de statuts par défaut (Backlog/In Progress/Done/Cancelled). Suppression interdite si des issues l'utilisent (ou migration proposée).

**Justification** : les catégories fixes garantissent les invariants sémantiques (done ne compte pas dans le reste à faire) et donneront aux agents MCP une lecture non ambiguë.

### ADR-6 : Architecture ADR (Action Domain Responder) avec `lorisleiva/laravel-actions`

**Décision** : l'application suit le pattern **Action Domain Responder**, implémenté avec le package `lorisleiva/laravel-actions`.

- **Action** : chaque opération métier (CreateIssue, MoveIssue, AddComment…) est UNE classe unique, single-responsibility, utilisant les traits du package (`AsObject`, `AsController`, plus tard `AsJob`/`AsListener` si besoin). Elle porte sa validation (`rules()`), son autorisation et son exécution (`handle()`). Une même action est appelable comme contrôleur HTTP (route → action) ET comme objet injecté — ce sera le point d'entrée naturel des outils MCP dans la change `mcp-access`.
- **Domain** : les modèles Eloquent constituent le Domain, sans couche de repository intermédiaire. Le Domain est assumé comme couplé à Eloquent — trade-off délibéré pour profiter des conventions Laravel (relations, scopes, casts, observers).
- **Responder** : la construction des réponses HTTP (pages Inertia, redirects) vit dans des classes Responder distinctes, par domaine. Une action retourne un résultat du Domain ; le Responder le transforme en réponse pour le canal sollicité (Inertia aujourd'hui, JSON pour MCP demain).

**Structure des dossiers** :

```
app/
 ├── Actions/{Domain}/        # ex. Actions/Issues/CreateIssueAction.php
 ├── Responders/{Domain}/     # ex. Responders/Issues/IssueListResponder.php
 ├── Models/                  # le Domain (Eloquent), conventions Laravel
 └── ...
```

**Alternatives rejetées** : contrôleurs classiques (logique métier non réutilisable par MCP sans refactor) ; repositories + Domain abstrait (indirection coûteuse, aucune projection de changement d'ORM prévue) ; Livewire (React est requis).

**Justification** : c'est LE point d'ancrage de la promesse "MCP trivial plus tard" — un outil MCP = une action existante appelée en `AsObject`, sans duplication de logique ni couche de traduction.

### ADR-7 : Identifiants — UUID (ou ULID) publics

**Décision** : ULID comme clé primaire des entités métier (issues, projects, workspaces).

**Justification** : idempotence-friendly pour les agents, IDs triables chronologiquement, pas d'énumération d'URLs numériques.

## Risks / Trade-offs

- [Fuite inter-tenant via une requête sans scope] → global scope Eloquent sur les modèles métier + test automatisé "cross-workspace access denied" par contrôleur critique.
- [Cycles dans la hiérarchie] → validation applicative à l'écriture du `parent_id` ; test dédié.
- [Statuts supprimés alors que des issues les référencent] → refus de suppression tant que des issues l'utilisent (message clair proposant la migration des issues vers un autre statut).
- [Dette "actions" si on bâcle la séparation dès le début] → règle de revue : aucune requête DB ni logique métier hors des Actions ; aucun `Inertia::render` hors des Responders.
- [ULID + Eloquent] → package `ramsey/uuid` avec support ULID ou colonne char(26) ; vérifier le tri par défaut des clés primaires.

## Migration Plan

Projet vert — pas de migration de données. Déploiement initial : `composer install`, `php artisan migrate --seed` (jeu de statuts par défaut créé à la création du workspace via observer, pas en seed global).

## Open Questions

- Pagination/filtres de la liste d'issues : format exact des filtres (query params) — à trancher pendant l'implémentation de l'UI, pas bloquant.
- Invitations : par email avec token signé (simple) — confirmé pendant l'implémentation si besoin d'emails asynchrones.
- i18n de l'UI : partir sur français ou anglais par défaut (dogfood d'abord : français acceptable, mais si onboarding clients internationaux → anglais). À trancher à l'implémentation.
