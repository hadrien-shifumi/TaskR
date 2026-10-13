## 1. Bootstrap application

- [x] 1.1 Créer le projet Laravel 12 + Inertia (React) + Tailwind à la racine du dépôt (`composer create-project` ou `laravel new` avec presets), configurer Postgres dans `.env.example`
- [x] 1.2 Installer et configurer `lorisleiva/laravel-actions` ; poser l'arborescence ADR (`app/Actions/{Domain}`, `app/Responders/{Domain}`) avec une première action + responder d'exemple
- [x] 1.3 Configurer les clés primaires ULID (migrations + modèles de base) via un trait/convention partagé
- [x] 1.3 Mettre en place Pest (tests) + une factory de base ; vérifier `php artisan test` au vert *(écart : PHPUnit conservé — déjà en place dans le scaffold Laravel 13, guidelines Boost, pas de changement de dépendances non approuvé)*
- [x] 1.4 Initialiser shadcn/ui (CLI, alias de chemins, thème de base) et installer les composants de départ (button, input, select, dialog, table, badge, dropdown-menu, form)
- [x] 1.5 Aménager le layout Inertia de base (navigation avec sélecteur de workspace, pages placeholder)

## 2. Workspaces et membres

- [ ] 2.1 Migrations : `workspaces`, `memberships` (user_id, workspace_id, role owner/member)
- [ ] 2.2 Modèles + global scope multi-tenant (trait `BelongsToWorkspace`) et policy d'appartenance
- [ ] 2.3 Auth (register/login Laravel standard) + création du premier workspace à l'inscription, avec les 4 statuts par défaut (observer/action `CreateWorkspace`)
- [ ] 2.4 Invitations par email (token signé) : action d'invitation (owner only), page d'acceptation, ajout du membre
- [ ] 2.5 Sélecteur de workspace actif (session) ; middleware qui résout le workspace courant sur toutes les routes
- [ ] 2.6 Tests : isolation inter-workspaces (accès direct par ID refusé), rôles (member ne peut pas inviter)

## 3. Statuts

- [ ] 3.1 Migration `statuses` (workspace_id, name, category, position) + contrainte check sur category
- [ ] 3.2 Actions CRUD statuts (owner only) : créer, renommer, réordonner, supprimer (refus si issues liées)
- [ ] 3.3 Page de gestion des statuts dans les réglages du workspace
- [ ] 3.4 Tests : catégories fixes exposées avec le statut, suppression protégée, permissions owner

## 4. Projets

- [ ] 4.1 Migration `projects` (workspace_id, name, description, archived_at)
- [ ] 4.2 Actions CRUD projets : créer, renommer, archiver/désarchiver ; suppression refusée si issues
- [ ] 4.3 UI : liste des projets + formulaire création/édition
- [ ] 4.4 Tests : archivage réversible, suppression conditionnée

## 5. Issues

- [ ] 5.1 Migrations : `issues` (workspace_id, project_id, parent_id, title, description, status_id, priority, due_date), `labels`, `issue_labels`, `issue_assignees`
- [ ] 5.2 Modèles + relations (parent/enfants, statut, labels, assignés)
- [ ] 5.3 Actions : CreateIssue, UpdateIssue, SetIssueParent (validation : même workspace + pas de cycle), SetAssignees, SetLabels, DeleteIssue, MoveIssue (changement de statut libre)
- [ ] 5.4 Actions en mode `AsController` + Responders + pages Inertia : liste filtrable (projet, statut/catégorie, priorité, labels, assigné, en retard), formulaire de création/édition, vue détail
- [ ] 5.5 Vue détail issue : métadonnées, enfants/parent, assignés, due date
- [ ] 5.6 Tests : CRUD, hiérarchie (enfant ajouté, cycle rejeté, cross-workspace rejeté), assignés multiples, filtres, ULID dans les URLs

## 6. Commentaires

- [ ] 6.1 Migration `comments` (issue_id, author_id, body, timestamps)
- [ ] 6.2 Actions AddComment / EditComment / DeleteComment (auteur only pour édition/suppression)
- [ ] 6.3 Fil de commentaires dans la vue détail issue (Markdown rendu, chronologique)
- [ ] 6.4 Tests : ajout, édition par auteur, refus pour un tiers

## 7. Vérification finale

- [ ] 7.1 Passe complète de tests au vert (`php artisan test`), dont les tests d'isolation inter-tenants
- [ ] 7.2 Revue du respect du pattern ADR : toute requête DB dans une Action, tout `Inertia::render` dans un Responder, aucune logique métier ailleurs
- [ ] 7.3 Dogfood minimal : créer un workspace, 2 projets, une hiérarchie epic/story/task, commenter, filtrer
