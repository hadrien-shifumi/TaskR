## Why

Taskr est une alternative légère à Jira pour gérer des tickets (projets, epics, stories, tasks), pensée pour être consommée par des agents IA via MCP autant que par des humains. Le dépôt est vierge : cette change pose les fondations multi-tenant du produit — le socle minimal pour dogfooder la création et le suivi d'issues avant d'y brancher l'accès MCP, les templates, le time tracking et la santé projet.

## What Changes

- Bootstrap de l'application : Laravel + Inertia + React (nouveau projet dans ce dépôt), UI basée sur shadcn/ui, structurée selon le pattern **ADR (Action Domain Responder)** avec `lorisleiva/laravel-actions` — les opérations métier sont des Actions uniques réutilisables, le Domain est porté par les modèles Eloquent (couplage assumé), et les réponses HTTP/Inertia sont construites par des Responders dédiés.
- Workspaces multi-tenant : chaque workspace est un tenant isolé logiquement (scoping par `workspace_id` sur toutes les données).
- Membres et authentification : comptes utilisateurs, inscription/connexion, appartenance à un ou plusieurs workspaces, rôles simples (`owner` / `member`).
- Projets : conteneurs simples d'issues au sein d'un workspace.
- Issues avec hiérarchie molle : une seule entité `issue` avec `parent_id` (profondeur libre — epics, stories, tasks ne sont que des usages de la même entité), titre, description Markdown, statut, priorité (4 niveaux), labels, plusieurs assignés, due date.
- Statuts personnalisables au niveau workspace, chacun rattaché à une catégorie sémantique fixe (`backlog` / `in_progress` / `done` / `cancelled`).
- Commentaires sur les issues : fil chronologique (auteur, corps Markdown, horodatage), éditables par leur auteur.
- UI web basique : liste des issues avec filtres simples, détail/édition d'une issue, gestion des statuts et des membres du workspace.

Hors périmètre (volontairement, changes ultérieures) : accès MCP et clés API, templates d'issues, estimates et time entries, santé/avancement projet, `target_date` sur les projets.

## Capabilities

### New Capabilities

- `workspace-management`: workspaces multi-tenant, membres, rôles owner/member, invitation de membres, isolation logique des données par workspace.
- `project-management`: création/édition de projets (conteneurs d'issues) au sein d'un workspace.
- `issue-tracking`: CRUD des issues avec hiérarchie molle (`parent_id`), priorité 4 niveaux, labels, assignés multiples, due date, commentaires.
- `status-management`: statuts personnalisables par workspace avec catégories sémantiques fixes.

### Modified Capabilities

(néant — dépôt vierge, aucune spec existante)

## Impact

- Nouveau projet Laravel + Inertia + React à la racine du dépôt (composer, package.json, migrations, etc.), avec le package `lorisleiva/laravel-actions` et l'arborescence ADR (`app/Actions`, `app/Responders`, `app/Models`).
- Base de données : schéma initial (users, workspaces, memberships, projects, issues, statuses, labels, assignements, comments).
- Toutes les requêtes applicatives passent par un scoping workspace obligatoire — pas d'accès aux données sans contexte tenant.
- Prépare le terrain pour l'accès agents : chaque outil MCP futur s'appuiera sur une Action existante (facet `AsObject`), sans duplication de logique.
