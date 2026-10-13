## ADDED Requirements

### Requirement: Serveur MCP sur `/mcp`
Le système SHALL exposer un serveur Model Context Protocol sur un endpoint unique `/mcp`, utilisant le transport HTTP streamable et le protocole MCP standard (JSON-RPC 2.0), indépendant du stack web/Inertia (pas de session, pas de cookies).

#### Scenario: Initialisation d'une session MCP
- **WHEN** un agent envoie une requête `initialize` valide sur `/mcp`
- **THEN** le serveur répond avec ses capacités (tools, resources) et la version du protocole, et la session est prête pour les appels d'outils

#### Scenario: Requête non-MCP
- **WHEN** une requête HTTP non conforme au protocole MCP arrive sur `/mcp`
- **THEN** elle est rejetée avec une erreur protocolaire, sans fuite de détail applicatif

### Requirement: Authentification des agents par personal access token
Chaque appel MCP SHALL être authentifié par un personal access token présenté par l'agent. Le token authentifie un utilisateur ; l'agent opère avec exactement les permissions de cet utilisateur (owner/member par workspace). Tout appel sans token valide SHALL être refusé.

#### Scenario: Appel avec token valide
- **WHEN** un agent appelle un outil MCP avec un token valide appartenant à Alice
- **THEN** l'appel est traité en tant qu'Alice, avec ses rôles dans ses workspaces

#### Scenario: Appel sans token ou avec token invalide
- **WHEN** un agent appelle un outil MCP sans token, avec un token mal formé, ou avec un token révoqué
- **THEN** l'appel est refusé avant toute exécution d'outil et aucune donnée n'est retournée

### Requirement: Gestion des tokens dans les réglages du compte
Le système SHALL permettre à chaque utilisateur de créer, lister et révoquer ses personal access tokens depuis une page Settings / API tokens. Le token n'est montré en clair qu'une seule fois à la création ; seul un hachage est stocké.

#### Scenario: Création d'un token
- **WHEN** Alice crée un token nommé "Agent Claude" depuis les réglages
- **THEN** le token est créé, sa valeur complète est affichée une seule fois, puis seul le nom et la date de création restent visibles

#### Scenario: Révocation immédiate
- **WHEN** Alice révoque le token "Agent Claude"
- **THEN** tout appel MCP ultérieur avec ce token est refusé

### Requirement: Isolation multi-tenant des appels d'outils
Un agent SHALL pouvoir opérer uniquement sur les workspaces dont l'utilisateur authentifié est membre. Le workspace cible est passé à chaque appel d'outil et SHALL être re-résolu à chaque requête MCP (vérification d'appartenance comprise), jamais mis en cache entre requêtes. Les global scopes Eloquent restent inchangés.

#### Scenario: Opération dans un workspace membre
- **WHEN** l'agent de Alice appelle `list_issues` avec l'identifiant d'un workspace dont Alice est membre
- **THEN** les issues de ce workspace sont retournées

#### Scenario: Refus cross-workspace
- **WHEN** l'agent de Alice appelle un outil en ciblant un workspace dont Alice n'est pas membre (ou avec un identifiant inexistant)
- **THEN** l'appel est refusé avec une erreur d'autorisation, sans divulguer l'existence du workspace

#### Scenario: Re-résolution à chaque requête
- **WHEN** Alice est retirée d'un workspace après une première requête MCP réussie, puis l'agent rappelle le même outil sur ce workspace
- **THEN** le second appel est refusé

### Requirement: Outils MCP projets
Le système SHALL exposer les outils `list_projects`, `create_project`, `update_project`, `archive_project`, chacun déléguant à l'action métier existante correspondante (mêmes validations, mêmes permissions que l'UI).

#### Scenario: Création d'un projet via MCP
- **WHEN** l'agent de Alice appelle `create_project` avec un nom valide pour un workspace dont elle est membre
- **THEN** le projet est créé et retourné avec son identifiant ULID

#### Scenario: Permission identique à l'UI
- **WHEN** un outil requiert une permission que l'utilisateur n'a pas (ex. une opération owner-only sur un workspace où il est member)
- **THEN** l'outil échoue exactement comme l'équivalent UI échouerait

### Requirement: Outils MCP issues
Le système SHALL exposer les outils `list_issues`, `create_issue`, `update_issue`, `delete_issue`, `move_issue`, `set_issue_parent`, `set_assignees`, `set_labels`, chacun déléguant à l'action métier existante correspondante. Tous les invariants (hiérarchie sans cycle, parent du même workspace, priorité 4 niveaux) SHALL être appliqués à l'identique.

#### Scenario: Création d'issue via MCP
- **WHEN** l'agent de Alice appelle `create_issue` avec un titre, un projet et un statut du workspace
- **THEN** l'issue est créée avec les mêmes règles que l'UI et retournée avec ses identifiants

#### Scenario: Cycle de hiérarchie rejeté via MCP
- **WHEN** l'agent appelle `set_issue_parent` pour définir A comme parent de B alors que B est déjà ancêtre de A
- **THEN** l'outil échoue avec l'erreur métier attendue, identique à celle de l'UI

#### Scenario: Refus inter-workspace sur un identifiant
- **WHEN** l'agent de Alice appelle `update_issue` avec l'ULID d'une issue d'un autre workspace
- **THEN** l'issue est introuvable pour l'agent et l'appel échoue (aucune fuite d'existence)

### Requirement: Outils MCP commentaires
Le système SHALL exposer `add_comment` et `list_comments` sur les issues, avec les mêmes règles d'autorisation que l'UI (édition/suppression réservées à l'auteur côté UI ; via MCP, l'agent agit en tant qu'utilisateur authentifié et devient l'auteur de ses commentaires).

#### Scenario: Ajout de commentaire via MCP
- **WHEN** l'agent de Alice appelle `add_comment` avec le corps du commentaire sur une issue de son workspace
- **THEN** le commentaire est créé avec Alice comme auteure et retourné

### Requirement: Outils MCP référentiels
Le système SHALL exposer `list_workspaces` (les workspaces de l'utilisateur authentifié, avec son rôle dans chacun), `list_statuses` (statuts du workspace avec leur catégorie sémantique) et `list_labels` (labels du workspace).

#### Scenario: Découverte des workspaces
- **WHEN** l'agent de Alice appelle `list_workspaces`
- **THEN** seuls les workspaces dont Alice est membre sont retournés, avec son rôle dans chacun

#### Scenario: Statuts avec catégories sémantiques
- **WHEN** l'agent appelle `list_statuses` pour un workspace
- **THEN** chaque statut est retourné avec sa catégorie (`backlog`/`in_progress`/`done`/`cancelled`), permettant un raisonnement non ambigu

### Requirement: Outils MCP time tracking (dépendance soft)
Le système SHALL exposer `log_time` et `list_time_entries` uniquement lorsque la capacité time-tracking est implémentée. Tant qu'elle ne l'est pas, cette change n'est pas bloquée et ces outils ne sont pas enregistrés.

#### Scenario: Outils présents après implémentation de time-tracking
- **WHEN** la change `time-tracking` est implémentée
- **THEN** le serveur MCP expose `log_time` et `list_time_entries` selon les mêmes règles d'isolation que les autres outils

### Requirement: Erreurs retournées proprement à l'agent
Tout échec de validation ou d'autorisation pendant un appel d'outil SHALL être retourné à l'agent comme résultat d'outil en erreur (isError) structuré et lisible — champs en cause pour les erreurs de validation, nature du refus pour les erreurs d'autorisation — sans trace HTTP ni stack trace.

#### Scenario: Erreur de validation actionnable
- **WHEN** l'agent appelle `create_issue` avec un titre vide
- **THEN** l'outil retourne un résultat en erreur désignant le champ `title` en cause, sans crash de la session

#### Scenario: Erreur d'autorisation explicite
- **WHEN** un appel d'outil est refusé pour raison d'appartenance ou de rôle
- **THEN** l'agent reçoit une erreur d'autorisation claire, distincte d'une erreur de validation

### Requirement: Pas de duplication de logique métier
Chaque outil MCP SHALL déléguer à une action `AsObject` existante ; aucune règle métier (validation, autorisation, invariants) ne SHALL être réimplémentée dans la couche MCP.

#### Scenario: Invariant garanti par l'action partagée
- **WHEN** un invariant métier est renforcé dans une action (ex. nouvelle validation sur CreateIssue)
- **THEN** l'outil MCP correspondant applique automatiquement ce renforcement sans modification de la couche MCP

### Requirement: Ressources MCP en lecture
Le système SHALL exposer les issues d'un projet comme ressources MCP en lecture seule (périmètre minimal : liste des issues d'un projet), soumises aux mêmes règles d'authentification et d'isolation que les outils.

#### Scenario: Lecture des issues d'un projet
- **WHEN** un agent authentifié lit la ressource des issues d'un projet d'un workspace dont l'utilisateur est membre
- **THEN** la liste des issues du projet est retournée

#### Scenario: Lecture refusée hors workspace membre
- **WHEN** un agent lit la ressource des issues d'un projet d'un workspace non membre
- **THEN** la lecture est refusée
