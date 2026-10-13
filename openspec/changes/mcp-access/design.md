## Context

La change `foundation` (archivée) a livré le socle : workspaces multi-tenant (trait `BelongsToWorkspace` + global scope + `WorkspaceContext` résolu par middleware), issues à hiérarchie molle, statuts custom à catégories sémantiques, commentaires — le tout via le pattern ADR avec `lorisleiva/laravel-actions` : chaque opération métier est une action unique, `AsObject` + `AsController`, portant sa validation (`rules()`), son autorisation (`authorize()`) et son exécution (`handle()`). `foundation/design.md` désignait déjà les actions `AsObject` comme « LE point d'ancrage de la promesse MCP trivial plus tard ».

Cette change tient cette promesse : exposer les données du workspace à des agents IA via un serveur MCP, en réutilisant les actions existantes sans dupliquer la moindre règle métier. Aujourd'hui, `composer show --direct` ne contient ni `laravel/mcp`, ni `laravel/sanctum` — aucun package d'auth par token n'est installé.

Décisions produit déjà prises pendant l'exploration :

- Transport : HTTP streamable, endpoint unique `/mcp`.
- Auth : personal access token porté par l'agent ; le token authentifie un utilisateur ; les permissions sont celles de l'utilisateur (owner/member par workspace).
- L'agent peut opérer sur n'importe quel workspace dont l'utilisateur est membre ; le workspace est passé à l'appel d'outil.
- Outils time-tracking (`log_time`, `list_time_entries`) : dépendance soft envers la change `time-tracking`.

## Goals / Non-Goals

**Goals:**

- Un agent IA authentifié par token peut faire le CRUD des tickets (projets, issues, commentaires) sur tout workspace dont l'utilisateur est membre, avec les mêmes invariants et permissions que l'UI.
- Zéro duplication de logique métier : un outil MCP = une action existante (ou une nouvelle action de lecture) appelée en `AsObject`.
- Isolation multi-tenant inaliénable côté MCP : re-résolution du workspace à chaque appel d'outil, global scopes inchangés, refus sans fuite d'information.
- Une expérience agent de qualité : erreurs de validation structurées et actionnables, référentiels exposés (`list_statuses` avec catégories sémantiques, `list_labels`, `list_workspaces` avec rôles).

**Non-Goals:**

- UI de configuration MCP (connexion d'agents, catalogues d'outils) — les tokens suffisent.
- SSO, OAuth, abilities granulaires par token (un token = toutes les permissions de l'utilisateur).
- Webhooks, rate limiting dédié, abonnements/notifications MCP.
- Outils d'administration (gestion des membres, des statuts) via MCP — lecture des statuts oui, écriture non (reste en UI).

## Decisions

### ADR-1 : Serveur MCP — package officiel `laravel/mcp` sur `/mcp`, transport HTTP streamable

**Décision** : installer le package officiel **`laravel/mcp`** et y enregistrer un serveur unique (par ex. `app/Mcp/Servers/TaskrServer.php`), exposé sur l'endpoint unique `/mcp` avec le transport HTTP streamable. Les outils sont déclarés dans le serveur avec leur schéma d'entrée ; chaque outil est une classe fine dans `app/Mcp/Tools/` qui résout le contexte, délègue à une action et sérialise le résultat.

**Nouvelle dépendance composer, soumise à validation de l'utilisateur avant `composer require`** (règle projet : pas de changement de dépendances sans approbation).

**Alternatives rejetées** :

- Réimplémenter JSON-RPC à la main (contrôleur + parsing des messages MCP) : le protocole MCP n'est pas juste « du JSON-RPC sur HTTP » — négociation de capacités, gestion de session streamable, framing des messages, versions du protocole, format des résultats d'outils et la compatibilité clients (Claude,Cursor, etc.) évoluent en continu. Réimplémenter = recoder et maintenir indéfiniment des dizaines de cas limites protocolaires déjà couverts par le package officiel, pour un bénéfice nul : notre valeur est la couche métier, pas la couche protocole.
- SDK PHP bas niveau (`modelcontextprotocol/sdk-php`) : correct mais non intégré à Laravel (pas de container, pas de conventions de publication de routes/config) ; plus de glue à maintenir que `laravel/mcp`.

**Justification** : package officiel maintenu par Laravel, intégré au framework (routage, config, `make:mcp-server`), qui nous laisse exactement ce qui nous appartient : définir des outils et leur comportement. L'endpoint unique `/mcp` simplifie la configuration côté clients (une URL, un token).

### ADR-2 : Authentification — personal access tokens via `laravel/sanctum`

**Décision** : installer `laravel/sanctum` (également une nouvelle dépendance, même validation utilisateur) et utiliser ses personal access tokens : table de tokens hachés, valeur complète montrée une seule fois à la création, middleware d'auth par token sur `/mcp`. Aucune ability Sanctum — un token porte toutes les permissions de l'utilisateur ; l'autorisation fine (owner/member) reste évaluée par workspace au moment de chaque appel d'outil.

**Alternatives rejetées** : table `api_tokens` maison + middleware custom (réécrire du code de sécurité sensible — hachage, comparaison à temps constant, expiration — que Sanctum fournit déjà testé et maintenu) ; OAuth2 (overkill pour des agents propres à un utilisateur).

**Justification** : Sanctum est LA voie Laravel pour les personal access tokens ; il ne nous fournit que le mécanisme d'auth (qui est l'utilisateur ?) et laisse l'autorisation (que peut-il faire ?) là où elle doit être : dans les actions et la résolution du workspace. Un agent compromis = compromission équivalente au compte utilisateur, revocable en un clic — d'où la page de gestion (spec `mcp-access`, requirement « Gestion des tokens »).

### ADR-3 : Résolution du workspace — paramètre explicite de chaque outil, re-résolu à chaque appel, jamais mis en cache

**Décision** :

1. Tout outil scoped à un workspace prend un paramètre `workspace_id` (seule exception : `list_workspaces`). L'agent déclare explicitement son contexte — découvrable via `list_workspaces`.
2. Avant toute exécution, une résolution partagée (action `ResolveAgentWorkspace`, `AsObject` only) vérifie que l'utilisateur authentifié par le token est membre du `workspace_id` fourni, puis positionne le `WorkspaceContext` pour la durée de l'appel d'outil.
3. Le contexte est réinitialisé (flush) à la fin de chaque appel d'outil, et re-résolu au suivant. Aucun état MCP n'est mis en cache entre requêtes : une session MCP longue porte l'authentification, jamais le workspace.

Pourquoi ce sens de résolution : le global scope `BelongsToWorkspace` filtre toutes les requêtes métier par le `WorkspaceContext` courant. Résoudre « workspace depuis l'entité visée » (ex. `update_issue(issue_id)` sans workspace) obligerait à chercher l'issue hors scope puis à en déduire le workspace — c'est-à-dire contourner le garde-fou central du produit. Faire porter `workspace_id` par l'appel garde le scope actif en continu : une issue d'un autre workspace est simplement introuvable, sans fuite d'existence, et le refus « pas membre du workspace » est explicite pour l'agent.

**Alternative rejetée** : workspace persisté dans la session MCP (établi à l'init puis réutilisé) — viole l'exigence de re-résolution (retrait d'un membre, changement de rôle) et rend les appels implicites donc difficiles à auditer.

### ADR-4 : Mapping outil → action — une classe d'outil fine, les actions comme unique point d'entrée

**Décision** : chaque outil MCP est une classe fine (`app/Mcp/Tools/`) : schéma d'entrée, résolution du workspace (ADR-3), appel de l'action en `AsObject`, sérialisation du résultat en données plates (ULIDs). Mapping retenu :

| Outil MCP | Point d'entrée |
|---|---|
| `list_workspaces` | nouvelle action de lecture `ListWorkspaces` (memberships de l'utilisateur, avec rôle) |
| `list_projects` | nouvelle action de lecture `ListProjects` |
| `create_project` | `Actions\Projects\CreateProject` |
| `update_project` | `Actions\Projects\UpdateProject` |
| `archive_project` | `Actions\Projects\ArchiveProject` |
| `list_issues` | nouvelle action de lecture `ListIssues` (filtres projet/statut/priorité/labels/assigné, pagination) |
| `create_issue` | `Actions\Issues\CreateIssue` |
| `update_issue` | `Actions\Issues\UpdateIssue` |
| `delete_issue` | `Actions\Issues\DeleteIssue` |
| `move_issue` | `Actions\Issues\MoveIssue` |
| `set_issue_parent` | `Actions\Issues\SetIssueParent` |
| `set_assignees` | `Actions\Issues\SetAssignees` |
| `set_labels` | `Actions\Issues\SetLabels` |
| `add_comment` | `Actions\Comments\AddComment` |
| `list_comments` | nouvelle action de lecture `ListComments` |
| `list_statuses` | nouvelle action de lecture `ListStatuses` (avec catégorie sémantique) |
| `list_labels` | nouvelle action de lecture `ListLabels` |
| `log_time`, `list_time_entries` | actions de la change `time-tracking` (dépendance soft) |

Deux conséquences assumées :

- **Actions de lecture nouvelles** : les specs `foundation` n'exigeaient pas d'actions de listing réutilisables (les pages UI font leurs requêtes dans les actions `Show*Page`). La règle de revue « aucune requête DB hors des Actions » s'applique aussi à la couche MCP : les listes deviennent donc de nouvelles actions `AsObject` uniquement, placées dans leurs domaines (`Actions/Issues/ListIssues`, etc.), réutilisables plus tard par toute API. Les outils ne contiennent aucune requête Eloquent.
- **Ajustements limités des actions existantes** : les actions d'écriture existantes sont déjà appelables en `AsObject` (`handle(Workspace $workspace, array $data)`) ; si l'appel programmatique révèle un couplage résiduel au `ActionRequest` (dans `authorize()`/`rules()`), l'ajustement se limite à rendre ce chemin propre — sans toucher à la logique métier ni au comportement des routes existantes. Toute modification d'action est couverte par les tests existants.

**Sérialisation** : les résultats d'outils sont des structures de données simples (tableaux associatifs plats, ULIDs, ISO 8601 pour les dates), jamais des modèles sérialisés bruts — les agents consomment du JSON stable et documenté.

### ADR-5 : Gestion des erreurs — tout échec devient un résultat d'outil en erreur, jamais un crash de session

**Décision** : l'exécution de chaque outil est enveloppée d'un gestionnaire d'erreurs unique au niveau du serveur MCP :

- `ValidationException` → résultat d'outil `isError` avec, en charge utile structurée, les champs en cause et leurs messages (`errors()->toArray()`) : l'agent peut corriger et retenter.
- Refus d'autorisation (résolution de workspace, rôle, auteur-only) → résultat d'erreur distinct, message stable et clair (« vous n'êtes pas membre de ce workspace », « cette opération est réservée au owner »), sans divulguer l'existence de ressources.
- Erreur inattendue → résultat d'erreur générique côté agent, détail complet loggé côté serveur. Aucune stack trace, aucune fuite HTTP.

**Justification** : le contrat agent est « un appel = un résultat exploitable ». Un crash de session pour un titre vide rendrait l'agent inutilisable ; un message d'erreur vague le rendrait inefficient. La distinction validation/autorisation permet à l'agent de distinguer « je me corrige » de « j'abandonne ».

### ADR-6 : Time tracking — dépendance soft résolue à l'enregistrement

**Décision** : les outils `log_time` et `list_time_entries` sont déclarés dans cette change mais enregistrés au serveur uniquement si la capacité time-tracking existe (test de présence des actions à l'enregistrement du serveur). Tant que la change `time-tracking` n'est pas implémentée, cette change est complète et autonome sans eux ; quand elle arrive, son implémentation branche les deux outils — pas d'aller-retour sur la couche MCP.

### ADR-7 : Ressources MCP — périmètre minimal, issues par projet

**Décision** : exposer en lecture une ressource MCP `taskr://workspaces/{workspace_id}/projects/{project_id}/issues` (liste des issues d'un projet), soumise aux mêmes règles d'auth et d'isolation que les outils (résolution du workspace à chaque lecture). Périmètre volontairement réduit : les outils `list_*` couvrent déjà les cas d'usage agents avec filtres ; la ressource sert aux clients qui parcourent naturellement par ressources. Pas de ressource par issue individuelle à ce stade.

### ADR-8 : UI tokens — page Settings / API tokens

**Décision** : une page Inertia « API tokens » dans les réglages du compte, avec actions dédiées `CreateApiToken` (génère, retourne la valeur en clair une seule fois) et `RevokeApiToken`, dans le domaine `Auth` de l'arborescence ADR. La valeur complète du token est affichée une unique fois dans un dialogue à la création ; ensuite, seuls le nom et l'horodatage sont visibles. La page liste les tokens de l'utilisateur connecté uniquement.

## Risks / Trade-offs

- [Token volé = compte utilisateur compromis] → valeur stockée hachée (jamais en clair en base), affichée une fois, révocation en un clic, aucune ability qui masque l'étendue du pouvoir du token.
- [Fuite inter-tenant via MCP] → `workspace_id` explicite par appel + vérification d'appartenance AVANT toute requête métier + global scopes inchangés + test dédié « cross-workspace refusé via MCP » et « re-résolution après retrait du membre ».
- [Contexte résiduel entre deux appels d'outils dans une même session MCP longue] → flush du `WorkspaceContext` à la fin de chaque appel d'outil ; test d'isolation séquentiel.
- [Maturité de `laravel/mcp`] → package officiel Laravel ; le périmètre utilisé (HTTP streamable + tools + resources) est le cœur du package. Contrepartie acceptée : suivre ses releases de sécurité.
- [Divergence UI/MCP si quelqu'un ajoute de la logique dans un outil] → règle de revue maintenue : un outil ne contient ni validation métier ni requête DB — seulement résolution de contexte, appel d'action, sérialisation.
- [Pagination des `list_*` mal dimensionnée pour des agents] → garder des limites strictes par défaut (ex. 25) et un paramètre de curseur simple ; à trancher en implémentation (Open Questions).

## Migration Plan

Déploiement sur l'existant, sans données à migrer :

1. Validation utilisateur des deux nouvelles dépendances (`laravel/mcp`, `laravel/sanctum`) → `composer require`.
2. Migrations Sanctum (table des tokens) — aucune migration métier.
3. Enregistrement du serveur MCP + route `/mcp` hors stack web/Inertia (middleware auth par token, sans session ni cookies).
4. Feature tests MCP (voir tasks.md) puis `php artisan test` complet au vert.

Rollback trivial : désactiver l'endpoint `/mcp` (route/config) et la page Settings/API tokens ; aucune donnée métier touchée.

## Open Questions

- Pagination des outils `list_*` : limit/offset vs curseur opaque — à trancher en implémentation, non bloquant.
- Date d'expiration des tokens : offir une durée de vie par défaut configurable ou création illimitée par défaut — à trancher en implémentation.
- Filtres de `list_issues` : exposer la même matrice de filtres que l'UI dès cette change, ou partir d'un sous-ensemble (projet, statut, assigné) et étendre selon les besoins agents réels.
