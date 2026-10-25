## ADDED Requirements

### Requirement: Archivage réversible des issues
Le système SHALL permettre d'archiver une issue dont le statut a la catégorie sémantique `done` ou `cancelled`, et de la désarchiver. L'archivage SHALL être réservé au Owner du workspace ; la désarchivation également. Une issue archivée porte un `archived_at` horodaté, reste consultable à son URL (commentaires et temps inclus) et n'est jamais supprimée par l'archivage.

#### Scenario: Owner archive une issue terminée
- **WHEN** le Owner archive une issue dont le statut a la catégorie `done`
- **THEN** l'issue porte un `archived_at` et disparaît des vues actives

#### Scenario: Member refusé
- **WHEN** un member tente d'archiver ou de désarchiver une issue
- **THEN** l'action est refusée avec une erreur d'autorisation

#### Scenario: Issue non close refusée
- **WHEN** le Owner tente d'archiver une issue dont le statut a la catégorie `backlog` ou `in_progress`
- **THEN** l'action est refusée avec une erreur explicite invitant à clore l'issue d'abord

#### Scenario: Désarchivation
- **WHEN** le Owner désarchive une issue archivée
- **THEN** l'issue revient dans les vues actives avec son statut, ses relations et son historique intacts

### Requirement: Cascade d'archivage sur la hiérarchie
Le système SHALL garantir qu'un enfant n'est jamais plus archivé que son parent : archiver une issue archive récursivement ses enfants non archivés ; désarchiver une issue désarchive récursivement ses enfants archivés. Tout chemin d'accès (UI, MCP) SHALL respecter cet invariant.

#### Scenario: Archivage d'un parent
- **WHEN** le Owner archive une issue done ayant deux enfants dont un actif
- **THEN** le parent et l'enfant actif sont archivés dans la même opération

#### Scenario: Désarchivation d'un sous-arbre
- **WHEN** le Owner désarchive un parent archivé
- **THEN** ses enfants archivés redeviennent actifs avec lui

### Requirement: Exclusion des issues archivées des vues actives
Les listes, boards, compteurs et indicateurs du présent (board kanban, liste cross-projet, santé projet, sélecteurs d'issues) SHALL exclure par défaut les issues archivées. Une issue archivée SHALL rester consultable sur sa fiche, et les liens de hiérarchie vers une issue archivée depuis une fiche accessible restent fonctionnels.

#### Scenario: Board sans archivées
- **WHEN** des issues d'un projet sont archivées
- **THEN** elles n'apparaissent plus dans les colonnes ni les compteurs du board kanban du projet

#### Scenario: Santé projet recalculée
- **WHEN** on compare la santé projet avant et après archivage d'issues done
- **THEN** les indicateurs excluent les issues archivées et restent concordants avec le board

#### Scenario: Fiche archivée consultable
- **WHEN** un membre ouvre l'URL d'une issue archivée de son périmètre
- **THEN** la fiche s'affiche avec un indicateur d'archivage, ses commentaires et ses temps

### Requirement: Filtre archived sur la liste des issues
La liste des issues SHALL accepter un paramètre `archived` à trois états : `exclude` (défaut, uniquement les actives), `include` (toutes), `only` (uniquement les archivées), composé avec les filtres existants (projet, statut, catégorie, priorité, labels, assigné, overdue).

#### Scenario: Comportement inchangé par défaut
- **WHEN** la liste est appelée sans le paramètre `archived`
- **THEN** seules les issues actives sont retournées, identiquement au comportement antérieur

#### Scenario: Historique seul
- **WHEN** la liste est appelée avec `archived=only` et un filtre projet
- **THEN** seules les issues archivées de ce projet sont retournées
