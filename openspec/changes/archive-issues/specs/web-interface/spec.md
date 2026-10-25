## ADDED Requirements

### Requirement: Vue Archives par projet
Le système SHALL fournir une vue « Archives » par projet listant les issues archivées du projet (titre, statut, catégorie, date d'archivage, ordre décroissant d'archivage), avec un état vide explicite et une entrée de navigation depuis le projet. Chaque ligne SHALL proposer une action « Désarchiver » visible uniquement du Owner ; un member voit la liste en lecture seule. Pas de déarchivage en masse.

#### Scenario: Consultation des archives
- **WHEN** un membre ouvre la vue Archives d'un projet
- **THEN** toutes les issues archivées du projet sont listées avec leur date d'archivage, et uniquement celles-ci

#### Scenario: Désarchivage depuis la vue
- **WHEN** le Owner clique « Désarchiver » sur une ligne
- **THEN** l'issue disparaît des archives et revient dans les vues actives du projet

#### Scenario: Member en lecture seule
- **WHEN** un member ouvre la vue Archives
- **THEN** la liste s'affiche sans les contrôles de désarchivage, et le refus backend reste la barrière effective

### Requirement: Archivage en masse depuis le board
Le board kanban SHALL exposer au Owner une action « Archiver les terminées » archivant en une opération toutes les issues non archivées du projet dont le statut a la catégorie `done` ou `cancelled`, derrière un dialogue de confirmation indiquant le nombre d'issues concernées. Le contrôle SHALL être masqué pour les members ; le refus backend reste la barrière effective.

#### Scenario: Masse sur un projet mixte
- **WHEN** le Owner confirme l'archivage en masse sur un projet contenant des issues done, cancelled et in_progress
- **THEN** seules les done et cancelled sont archivées et un message confirme le nombre archivé

#### Scenario: Rien à archiver
- **WHEN** le projet ne contient aucune issue done/cancelled non archivée
- **THEN** l'action n'archive rien et un message « rien à archiver » est affiché

### Requirement: Contrôles d'archivage sur la fiche issue
La fiche d'une issue SHALL présenter une action d'archivage (catégorie `done`/`cancelled` uniquement) et, pour une issue archivée, un indicateur d'état et une action de désarchivage — visibles du Owner uniquement. L'action unitaire respecte la cascade sur la hiérarchie.

#### Scenario: Archivage depuis la fiche
- **WHEN** le Owner archive une issue done depuis sa fiche
- **THEN** la fiche reflète l'état archivé et l'issue disparaît des vues actives
