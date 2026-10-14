## 1. Dépendances et configuration

- [x] 1.1 Faire valider par l'utilisateur les deux nouvelles dépendances, puis `composer require laravel/mcp laravel/sanctum` (règle projet : pas de dépendance sans approbation)
- [x] 1.2 Publier la config Sanctum, créer le serveur MCP (`php artisan make:mcp-server TaskrServer`) et l'exposer sur `/mcp` en transport HTTP streamable, hors stack web/Inertia (pas de session, pas de cookies, middleware auth par token)
- [x] 1.3 Vérifier que `/mcp` répond correctement au handshake `initialize` (capabilities tools + resources) et rejette les requêtes non-MCP

## 2. Tokens d'accès (modèle et actions)

- [x] 2.1 Migration des personal access tokens Sanctum ; s'assurer que la valeur n'est stockée que hachée
- [x] 2.2 Actions `CreateApiToken` (génère, retourne la valeur en clair une seule fois) et `RevokeApiToken`, dans `app/Actions/Auth`, en `AsObject` + `AsController`
- [x] 2.3 Action `ResolveAgentWorkspace` (`AsObject` only) : vérifie l'appartenance de l'utilisateur authentifié au `workspace_id` fourni, positionne le `WorkspaceContext` pour l'appel courant, avec flush en fin d'appel (re-résolu à chaque appel, jamais mis en cache)

## 3. UI Settings / API tokens

- [x] 3.1 Page Inertia « API tokens » dans les réglages du compte : liste (nom, date), formulaire de création, bouton de révocation (composants shadcn/ui existants : table, dialog, button)
- [x] 3.2 Dialogue d'affichage unique de la valeur complète à la création, avec avertissement qu'elle ne sera plus montrée
- [x] 3.3 Tests : création, affichage unique, révocation immédiate (token révoqué refusé sur `/mcp`)

## 4. Serveur MCP — socle

- [x] 4.1 Gestionnaire d'erreurs unique au niveau du serveur : `ValidationException` → résultat `isError` avec champs en cause ; refus d'autorisation → erreur distincte et stable ; erreur inattendue → message générique + log serveur (aucune stack trace côté agent)
- [x] 4.2 Enveloppe d'exécution des outils : résolution du workspace (via `ResolveAgentWorkspace`), appel d'action, sérialisation en tableaux plats (ULID, dates ISO 8601)
- [x] 4.3 S'assurer qu'aucun outil ne contient de requête DB ni de validation métier (règle de revue ADR : résolution + appel d'action + sérialisation uniquement)

## 5. Actions de lecture réutilisables

- [x] 5.1 Créer les actions `AsObject` only : `ListWorkspaces` (memberships de l'utilisateur avec rôle), `ListProjects`, `ListIssues` (filtres + pagination), `ListComments`, `ListStatuses` (avec catégorie sémantique), `ListLabels`
- [x] 5.2 Vérifier que les actions d'écriture existantes sont appelables proprement en `AsObject` depuis la couche MCP ; ajustements limités au strict nécessaire, couverts par les tests existants

## 6. Outils MCP

- [x] 6.1 Outils projets : `list_projects`, `create_project`, `update_project`, `archive_project` (délégation aux actions existantes, `workspace_id` en paramètre)
- [x] 6.2 Outils issues : `list_issues`, `create_issue`, `update_issue`, `delete_issue`, `move_issue`, `set_issue_parent`, `set_assignees`, `set_labels` — tous les invariants (cycle, parent du même workspace, priorité) appliqués par les actions
- [x] 6.3 Outils commentaires : `add_comment` (l'utilisateur authentifié devient l'auteur), `list_comments`
- [x] 6.4 Outils référentiels : `list_workspaces` (avec rôle), `list_statuses` (avec catégorie), `list_labels`
- [x] 6.5 Outils time tracking : `log_time` et `list_time_entries` enregistrés (la capacité time-tracking est implémentée)

## 7. Ressources MCP

- [x] 7.1 Ressource en lecture `taskr://workspaces/{workspace_id}/projects/{project_id}/issues` (issues d'un projet), même auth et même isolation que les outils (refus si non membre)

## 8. Tests feature MCP

- [x] 8.1 Handshake et auth : `initialize` réussit avec token valide ; refus sans token / token invalide / token révoqué (aucune donnée retournée)
- [x] 8.2 Création d'issue via MCP (`create_issue`) : issue créée avec les mêmes règles que l'UI, ULID retourné, visible ensuite dans l'UI
- [x] 8.3 Isolation : appel d'outil ciblant un workspace non membre refusé sans fuite d'existence ; `update_issue` sur une ULID d'un autre workspace échoue ; re-résolution après retrait du membre (second appel refusé)
- [x] 8.4 Erreurs agent : validation (`create_issue` sans titre → champ `title` désigné en erreur, session MCP intacte) vs autorisation (message distinct)
- [x] 8.5 Invariants via MCP : cycle de hiérarchie rejeté par `set_issue_parent` ; permission identique à l'UI (isolation membre/non-membre et auteur appliquée par les actions partagées — aucun outil owner-only n'existe dans le catalogue MCP)

## 9. Vérification finale

- [x] 9.1 Passe complète de tests au vert (`php artisan test`), dont les tests d'isolation inter-tenants existants et les nouveaux tests MCP
- [x] 9.2 `vendor/bin/pint --dirty --format agent` au propre
- [ ] 9.3 Dogfood agent minimal : avec un vrai client MCP (ex. Claude Desktop/Cursor) et un token réel — `list_workspaces` → `create_project` → `create_issue` hiérarchisée → `add_comment` → `list_issues` filtrée
- [x] 9.4 Revue du respect du pattern ADR : toute requête DB dans une Action, aucun `Inertia::render` hors Responder, aucune logique métier dans `app/Mcp/`
