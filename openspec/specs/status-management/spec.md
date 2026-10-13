
### Requirement: Statuts personnalisables par workspace
Les statuts sont définis au niveau du workspace. Un `owner` SHALL pouvoir créer, renommer et réordonner des statuts. Chaque statut comporte un nom libre et une catégorie sémantique.

#### Scenario: Création d'un statut
- **WHEN** un owner crée le statut "En revue" de catégorie in_progress
- **THEN** le statut est utilisable par toutes les issues du workspace

#### Scenario: Réordonnancement des statuts
- **WHEN** un owner déplace "En revue" avant "In Progress"
- **THEN** l'ordre d'affichage des statuts reflète ce choix partout dans l'UI

### Requirement: Catégories sémantiques fixes
Chaque statut MUST appartenir à exactement une catégorie parmi : backlog, in_progress, done, cancelled. La liste des catégories est fixe et non extensible. La catégorie est exposée avec le statut pour toute lecture (UI et futures API agents).

#### Scenario: Exposition de la catégorie
- **WHEN** une issue au statut "Fusionné" (catégorie done) est lue
- **THEN** la réponse inclut à la fois le nom du statut et sa catégorie done

### Requirement: Statuts par défaut
À la création d'un workspace, le système SHALL créer les statuts par défaut : Backlog (backlog), In Progress (in_progress), Done (done), Cancelled (cancelled).

#### Scenario: Workspace neuf
- **WHEN** un utilisateur crée un workspace
- **THEN** les 4 statuts par défaut existent immédiatement

### Requirement: Suppression protégée
La suppression d'un statut MUST être refusée tant que des issues l'utilisent ; l'UI proposera de déplacer ces issues vers un autre statut avant suppression.

#### Scenario: Suppression refusée
- **WHEN** un owner tente de supprimer le statut "En revue" utilisé par 2 issues
- **THEN** la suppression est refusée avec un message indiquant le nombre d'issues concernées

#### Scenario: Suppression après migration
- **WHEN** les 2 issues sont déplacées vers "Done" puis l'owner supprime "En revue"
- **THEN** le statut disparaît du workspace

### Requirement: Gestion réservée aux owners
Seuls les `owner` MUST pouvoir créer, modifier, réordonner ou supprimer des statuts ; toute tentative par un `member` sera refusée.

#### Scenario: Tentative par un member
- **WHEN** un `member` tente de créer un statut
- **THEN** l'action est refusée
