## ADDED Requirements

### Requirement: Outils MCP connaissance
Le système SHALL exposer les outils `search_knowledge` (recherche sémantique : requête naturelle, filtre projet optionnel, résultats classés avec identifiant, titre, extrait et score), `list_knowledge` (arborescence des titres et liaisons projet d'un workspace), `get_knowledge` (contenu intégral d'une page), `create_knowledge` et `update_knowledge` (titre, corps Markdown, parent, projet optionnels), chacun déléguant à l'action métier correspondante avec les mêmes validations, invariants (hiérarchie sans cycle, projet du même workspace) et permissions que l'UI. L'isolation multi-tenant (re-résolution du workspace, refus sans fuite d'existence) et le contrat d'erreurs structurées s'appliquent à l'identique des autres outils.

#### Scenario: Imprégnation du contexte par l'agent
- **WHEN** l'agent de Alice appelle `list_knowledge` puis `get_knowledge` sur des pages d'un workspace dont elle est membre
- **THEN** l'agent obtient l'arborescence des titres puis le contenu intégral demandé

#### Scenario: Recherche sémantique via MCP
- **WHEN** l'agent appelle `search_knowledge` avec la question naturelle "comment gère-t-on les environnements de staging"
- **THEN** les pages les plus sémantiquement proches sont retournées avec titre, extrait et score, utilisables pour un appel `get_knowledge` ciblé

#### Scenario: Maintenance de la connaissance par l'agent
- **WHEN** l'agent appelle `update_knowledge` sur une page du workspace avec un nouveau corps
- **THEN** la page est mise à jour comme via l'UI (dernier éditeur = utilisateur authentifié) et re-vectorisée

#### Scenario: Erreur de configuration d'embeddings via MCP
- **WHEN** l'agent appelle `search_knowledge` alors qu'aucun endpoint d'embeddings n'est configuré
- **THEN** l'outil retourne un résultat en erreur clair, distinct d'une erreur de validation ou d'autorisation, sans crash de session

#### Scenario: Refus cross-workspace
- **WHEN** l'agent de Alice appelle `get_knowledge` avec l'ULID d'une page d'un workspace dont elle n'est pas membre
- **THEN** l'appel échoue sans divulguer l'existence de la page
