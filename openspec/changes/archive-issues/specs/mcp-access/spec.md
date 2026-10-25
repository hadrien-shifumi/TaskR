## MODIFIED Requirements

### Requirement: Outils MCP issues
Le système SHALL exposer les outils `list_issues`, `create_issue`, `update_issue`, `delete_issue`, `move_issue`, `set_issue_parent`, `set_assignees`, `set_labels`, chacun déléguant à l'action métier existante correspondante. Tous les invariants (hiérarchie sans cycle, parent du même workspace, priorité 4 niveaux) SHALL être appliqués à l'identique. `list_issues` SHALL accepter un paramètre `archived` à trois états — `exclude` (défaut : uniquement les issues actives, comportement inchangé pour les appelants existants), `include` (toutes), `only` (uniquement les archivées) — composé avec les filtres existants. Aucun outil d'archivage ou de désarchivage d'issue n'est exposé : l'archivage est une opération de gestion humaine ; les issues archivées restent lisibles par les outils existants.

#### Scenario: Création d'issue via MCP
- **WHEN** l'agent de Alice appelle `create_issue` avec un titre, un projet et un statut du workspace
- **THEN** l'issue est créée avec les mêmes règles que l'UI et retournée avec ses identifiants

#### Scenario: Cycle de hiérarchie rejeté via MCP
- **WHEN** l'agent appelle `set_issue_parent` pour définir A comme parent de B alors que B est déjà ancêtre de A
- **THEN** l'outil échoue avec l'erreur métier attendue, identique à celle de l'UI

#### Scenario: Refus inter-workspace sur un identifiant
- **WHEN** l'agent de Alice appelle `update_issue` avec l'ULID d'une issue d'un autre workspace
- **THEN** l'issue est introuvable pour l'agent et l'appel échoue (aucune fuite d'existence)

#### Scenario: Historique archivé via MCP
- **WHEN** l'agent appelle `list_issues` avec `archived=only` sur un workspace
- **THEN** seules les issues archivées sont retournées, filtrables par projet et statut

#### Scenario: Défaut inchangé
- **WHEN** l'agent appelle `list_issues` sans le paramètre `archived`
- **THEN** les issues archivées sont exclues, exactement comme avant l'introduction du paramètre
