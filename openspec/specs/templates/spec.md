# templates Specification

## Purpose
Gabarits d'issues définis au niveau du workspace pour standardiser la forme des tickets par nature (bug, tâche de développement, analyse…). Non bloquants : ils pré-remplissent description, priorité et labels à la création d'une issue, sans jamais contraindre — toute valeur explicite prime, et les issues restent découplées des gabarits.
## Requirements
### Requirement: Définition des gabarits
Le système SHALL permettre de définir des gabarits d'issues. Un gabarit comporte : un nom (requis, unique au sein du workspace), un corps de description Markdown (le contenu gabarit à pré-remplir), une priorité par défaut optionnelle (none/low/high/urgent) et des labels par défaut optionnels appartenant au même workspace.

#### Scenario: Création d'un gabarit complet
- **WHEN** un owner crée le gabarit "Bug" avec un corps Markdown ("## Contexte\n## Étapes de reproduction\n## Comportement attendu"), la priorité par défaut high et 2 labels par défaut
- **THEN** le gabarit est créé avec toutes ces valeurs et est sélectionnable à la création d'issue

#### Scenario: Création d'un gabarit minimal
- **WHEN** un owner crée un gabarit avec uniquement un nom
- **THEN** le gabarit est créé, sans priorité ni labels par défaut

#### Scenario: Nom dupliqué refusé
- **WHEN** un owner crée un gabarit "Bug" alors qu'un gabarit "Bug" existe déjà dans le même workspace
- **THEN** la création est refusée avec une erreur de validation
- **THEN** la création d'un gabarit "Bug" dans un autre workspace reste possible

### Requirement: Gabarits au niveau du workspace
Les gabarits SHALL être définis au niveau du workspace et partagés par tous ses projets. Un gabarit ne MUST jamais être visible ni utilisable depuis un autre workspace.

#### Scenario: Isolation inter-workspaces
- **WHEN** un membre du workspace A tente d'appliquer le gabarit du workspace B en fournissant son identifiant
- **THEN** l'action est refusée comme si le gabarit n'existait pas

#### Scenario: Réutilisation cross-projets
- **WHEN** un membre applique le gabarit "Bug" à la création d'une issue dans deux projets différents du même workspace
- **THEN** le gabarit est applicable dans les deux cas

### Requirement: Gabarits non bloquants
L'application d'un gabarit SHALL uniquement pré-remplir l'issue à sa création, sans jamais contraindre. Pour chaque champ (description, priorité, labels) : si la requête de création ne fournit pas de valeur explicite, la valeur par défaut du gabarit s'applique ; toute valeur explicite prime sur le gabarit. Une issue créée sans gabarit SHALL rester pleinement valide.

#### Scenario: Pré-remplissage à la création
- **WHEN** un membre crée une issue avec le gabarit "Bug" en ne fournissant qu'un titre
- **THEN** l'issue est créée avec la description, la priorité et les labels par défaut du gabarit

#### Scenario: Valeur explicite prioritaire
- **WHEN** un membre crée une issue avec le gabarit "Bug" (priorité par défaut high) en fournissant explicitement la priorité urgent
- **THEN** l'issue est créée avec la priorité urgent

#### Scenario: Création sans gabarit
- **WHEN** un membre crée une issue sans sélectionner de gabarit
- **THEN** l'issue est créée selon les règles existantes, sans aucune valeur imposée

### Requirement: Indépendance des issues existantes
Le système SHALL conserver les gabarits et les issues découplés : l'issue ne référence pas le gabarit appliqué. Éditer ou supprimer un gabarit ne MUST avoir aucun effet sur les issues existantes, qui restent librement éditables après création.

#### Scenario: Suppression du gabarit après création
- **WHEN** un owner supprime le gabarit "Bug" alors que des issues ont été créées avec
- **THEN** les issues existantes sont inchangées (contenu, priorité, labels)

#### Scenario: Édition libre après création
- **WHEN** un membre modifie la description, la priorité ou les labels d'une issue créée via un gabarit
- **THEN** la modification est acceptée sans contrainte liée au gabarit

### Requirement: Application à la création par tout membre
Tout membre du workspace SHALL pouvoir appliquer un gabarit lors de la création d'une issue, dans n'importe quel projet du workspace. L'application se fait uniquement à la création, jamais rétroactivement ni à l'édition d'une issue existante.

#### Scenario: Application par un member
- **WHEN** un `member` crée une issue en sélectionnant le gabarit "Analyse fonctionnelle"
- **THEN** l'issue est créée avec les valeurs pré-remplies du gabarit

#### Scenario: Application à l'édition refusée
- **WHEN** un membre tente d'appliquer un gabarit à une issue existante via le flux d'édition
- **THEN** aucune pré-remplissage n'est proposé sur l'édition d'issue

### Requirement: Gestion réservée aux owners
Seuls les `owner` MUST pouvoir créer, modifier ou supprimer des gabarits ; toute tentative par un `member` sera refusée.

#### Scenario: Tentative de création par un member
- **WHEN** un `member` tente de créer un gabarit
- **THEN** l'action est refusée

#### Scenario: Consultation par un member
- **WHEN** un `member` consulte la liste des gabarits du workspace
- **THEN** la liste est accessible en lecture (nécessaire au sélecteur de création), mais aucune action de gestion n'est disponible

