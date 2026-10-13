## Why

Taskr est conçu pour être piloté par des agents IA autant que par des humains — c'est la raison d'être du produit : automatiser le CRUD des tickets sans passer par l'UI web. La change `foundation` a posé le socle exactement pour ça : pattern ADR où chaque opération métier est une action `AsObject` réutilisable, et scoping multi-tenant inaliénable. Cette change ouvre ce socle aux agents via un serveur MCP : chaque outil MCP s'appuie sur une action existante, sans aucune logique métier dupliquée.

## What Changes

- **Serveur MCP** : serveur Model Context Protocol exposé sur `/mcp` (endpoint unique), transport HTTP streamable, construit avec le package officiel **`laravel/mcp`** — nouvelle dépendance composer, soumise à validation de l'utilisateur avant installation (justification dans design.md).
- **Authentification des agents par personal access token** : création/gestion des tokens dans les réglages du compte (page Settings / API tokens) ; le token authentifie un utilisateur ; les permissions de l'agent sont exactement celles de l'utilisateur (member/owner, par workspace).
- **Outils MCP couvrant le CRUD existant**, chacun déléguant à une action `AsObject` existante :
  - Projets : `list_projects`, `create_project`, `update_project`, `archive_project`
  - Issues : `list_issues`, `create_issue`, `update_issue`, `delete_issue`, `move_issue`, `set_issue_parent`, `set_assignees`, `set_labels`
  - Commentaires : `add_comment`, `list_comments`
  - Référentiels : `list_statuses`, `list_labels`, `list_workspaces`
  - Time tracking : `log_time`, `list_time_entries` — **dépendance soft** envers la change `time-tracking` (livrés avec elle, ne bloquent pas celle-ci)
- **Isolation multi-tenant garantie** : l'agent opère en tant qu'un utilisateur authentifié, dans les workspaces dont il est membre ; le workspace est un paramètre de l'appel d'outil et est re-résolu à chaque requête MCP (jamais mis en cache entre requêtes) ; les global scopes Eloquent restent inchangés.
- **Erreurs propres pour l'agent** : les échecs de validation et d'autorisation sont retournés à l'agent comme résultats d'outils structurés (isError), lisibles et actionnables.
- **Ressources MCP en lecture** (périmètre minimal) : issues d'un projet exposées comme ressources MCP.

Hors périmètre (volontairement) : UI de configuration MCP, SSO, webhooks, abilities granulaires par token, rate limiting dédié.

## Capabilities

### New Capabilities

- `mcp-access`: serveur MCP sur `/mcp`, authentification par personal access token, outils MCP exposant le CRUD des projets/issues/commentaires et les référentiels du workspace, isolation multi-tenant par re-résolution du contexte à chaque appel.

### Modified Capabilities

(néant — aucune exigence des specs existantes (`workspace-management`, `project-management`, `issue-tracking`, `status-management`) ne change : les outils MCP consomment les mêmes invariants, ils ne les modifient pas)

## Impact

- **Nouvelles dépendances composer** : `laravel/mcp` (serveur MCP officiel) et `laravel/sanctum` (personal access tokens) — installation soumise à validation de l'utilisateur avant `composer require`.
- **Base de données** : table des personal access tokens (token stocké haché, valeur pleine visible une seule fois à la création).
- **Routes** : endpoint `/mcp` isolé du stack web/Inertia (pas de session, middleware d'auth par token).
- **Code** : nouvelle arborescence `app/Mcp/` (serveur, outils, résolution du contexte par appel) ; les actions existantes dans `app/Actions/` ne sont pas modifiées dans leur logique métier (ajustements limités au strict nécessaire pour un appel programmatique propre).
- **UI** : page Settings / API tokens (création, révocation, liste des tokens de l'utilisateur connecté).
- **Tests** : feature tests des outils MCP (création d'issue via MCP, refus cross-workspace, token invalide/révoqué).
