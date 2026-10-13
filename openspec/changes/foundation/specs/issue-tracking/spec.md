## ADDED Requirements

### Requirement: CRUD issue
Le système SHALL permettre aux membres de créer, lire, modifier et supprimer des issues. Une issue comporte : titre (requis), description Markdown, statut, priorité (4 niveaux fixes : none/low/high/urgent), labels (libres), zéro ou plusieurs assignés, due date optionnelle, projet d'appartenance.

#### Scenario: Création minimale
- **WHEN** un membre crée une issue avec uniquement un titre dans un projet
- **THEN** l'issue est créée avec le statut par défaut du workspace et la priorité none

#### Scenario: Création complète
- **WHEN** un membre crée une issue avec description, statut choisi, priorité high, 2 labels, 2 assignés et une due date
- **THEN** l'issue est créée avec toutes ces valeurs et apparaît dans les filtres correspondants

### Requirement: Hiérarchie molle
Toute issue MAY être rattachée à une issue parente du même workspace via `parent_id`, sans limite de profondeur et sans notion de type (Epic/Story/Task ne sont que des usages). Le système SHALL rejeter tout rattachement créant un cycle.

#### Scenario: Issue enfant
- **WHEN** un membre rattache l'issue "UI formulaire" à l'issue parente "Page de profil"
- **THEN** la parente affiche "UI formulaire" parmi ses enfants

#### Scenario: Cycle rejeté
- **WHEN** un membre tente de définir A comme parent de B alors que B est déjà ancêtre de A
- **THEN** l'action est refusée avec une erreur explicite

#### Scenario: Parent d'un autre workspace rejeté
- **WHEN** le parent proposé appartient à un autre workspace
- **THEN** le rattachement est refusé

### Requirement: Assignation multiple
Une issue MAY avoir plusieurs assignés simultanément ; le système SHALL permettre d'ajouter et de retirer chaque assigné indépendamment, sans modifier les autres.

#### Scenario: Deux assignés front et back
- **WHEN** un membre assigne Sam puis Léa à une issue
- **THEN** l'issue affiche Sam et Léa comme assignés

### Requirement: Statut et catégories sémantiques
Chaque issue porte un statut du workspace, qui référence une catégorie sémantique fixe (backlog / in_progress / done / cancelled). Changer de statut SHALL être possible à tout moment sans contrainte de transition.

#### Scenario: Passage direct à done
- **WHEN** un membre fait passer une issue de Backlog à Done sans étape intermédiaire
- **THEN** le changement est accepté immédiatement

### Requirement: Commentaires sur les issues
Le système SHALL fournir un fil de commentaires chronologique sur chaque issue : auteur, corps Markdown, horodatage. Un commentaire n'est éditable ou supprimable que par son auteur.

#### Scenario: Commentaire ajouté
- **WHEN** un membre ajoute un commentaire sur une issue
- **THEN** le commentaire apparaît en fin de fil avec son auteur et l'heure de création

#### Scenario: Édition par un tiers refusée
- **WHEN** un utilisateur tente d'éditer le commentaire d'un autre membre
- **THEN** l'action est refusée

### Requirement: Liste et filtres
Le système SHALL fournir une liste d'issues du workspace filtrable au minimum par projet, statut (et catégorie), priorité, labels, assigné, et présence d'une due date dépassée.

#### Scenario: Filtre par statut
- **WHEN** un membre filtre la liste sur le statut "In Progress"
- **THEN** seules les issues ayant ce statut sont listées, tous projets confondus du workspace

### Requirement: Identifiants opaques
Les issues SHALL utiliser des identifiants opaques non séquentiels (ULID) exposés dans les URLs et réponses.

#### Scenario: URL d'une issue
- **WHEN** une issue est créée
- **THEN** son URL contient un ULID, sans exposer de compteur séquentiel
