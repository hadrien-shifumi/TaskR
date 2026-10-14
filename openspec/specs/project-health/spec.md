# project-health Specification

## Purpose
Pilotage de la tenue d'un projet par convention : date cible optionnelle par projet et page Health affichant des indicateurs toujours calculés, jamais stockés — répartition des issues par catégorie de statut, pourcentage terminé, retard et risque par rapport à la date cible.
## Requirements
### Requirement: Date cible de projet
Le système SHALL permettre d'attribuer à chaque projet une date cible optionnelle (`target_date`, date nullable), éditable à la création et à tout moment par les membres du workspace.

#### Scenario: Définition d'une date cible
- **WHEN** un membre crée un projet "Refonte site vitrine" avec une `target_date` au 31 décembre
- **THEN** le projet est créé avec cette date cible, visible dans le formulaire d'édition du projet

#### Scenario: Projet sans date cible
- **WHEN** un membre crée un projet sans `target_date`
- **THEN** le projet est créé sans date cible et les indicateurs de projection de retard ne s'affichent pas pour ce projet

#### Scenario: Modification de la date cible
- **WHEN** un membre modifie la `target_date` d'un projet existant
- **THEN** la nouvelle valeur est enregistrée et tous les indicateurs de santé du projet reflètent immédiatement la nouvelle date cible

### Requirement: Santé calculée, jamais stockée
La santé d'un projet SHALL être systématiquement calculée à la lecture à partir des données existantes (issues, catégories de statut, due dates, estimations et time entries). Le système ne stockera PAS de valeur de santé et n'exigera aucune saisie de santé.

#### Scenario: Recalcul automatique
- **WHEN** une issue d'un projet passe au statut Done alors que la page Health du projet est ensuite consultée
- **THEN** le pourcentage terminé affiché intègre cette issue sans aucune action de rafraîchissement manuel ni tâche différée

### Requirement: Indicateurs d'avancement
Le système SHALL calculer pour chaque projet : le nombre d'issues par catégorie de statut (backlog / in_progress / done / cancelled) et le pourcentage terminé = (issues done + issues cancelled) / total des issues du projet, tous niveaux de hiérarchie confondus.

#### Scenario: Répartition par catégorie
- **WHEN** un projet contient 3 issues backlog, 2 en cours, 4 done et 1 cancelled
- **THEN** la page Health affiche ces 4 compteurs par catégorie et le pourcentage terminé de 50 % (5 terminées sur 10)

#### Scenario: Projet sans issue
- **WHEN** un membre consulte la page Health d'un projet ne contenant aucune issue
- **THEN** tous les compteurs affichent 0, le pourcentage terminé est non applicable et le statut de projection est `ok`

### Requirement: Issues en retard
Le système SHALL compter comme "en retard" toute issue du projet dont la due date est strictement antérieure à la date du jour et dont la catégorie de statut n'est ni `done` ni `cancelled`.

#### Scenario: Issue due dépassée
- **WHEN** un projet contient une issue avec une due date passée, toujours en catégorie in_progress
- **THEN** la page Health affiche 1 issue en retard

#### Scenario: Issue terminée avec due date dépassée
- **WHEN** une issue passée en catégorie done avait une due date désormais dépassée
- **THEN** elle n'est pas comptée comme en retard

### Requirement: Ratio de consommation des heures
Le système SHALL calculer, à partir des estimations et time entries fournis par la change `time-tracking`, le ratio de consommation = heures logguées / heures estimées (sommes sur l'ensemble des issues du projet). Le ratio n'est affiché que si le total estimé est strictement positif.

#### Scenario: Consommation à 80 %
- **WHEN** les issues d'un projet totalisent 10 heures estimées et 8 heures logguées
- **THEN** la page Health affiche un ratio de consommation de 80 %

#### Scenario: Aucune estimation
- **WHEN** aucune issue du projet ne porte d'estimation
- **THEN** le ratio de consommation n'est pas affiché

### Requirement: Projection de retard
Lorsque le projet porte une `target_date` et contient au moins une issue, le système SHALL calculer un statut de projection parmi `ok` / `at-risk` / `late`, à partir de :

- pourcentage attendu à date = clamp((date du jour − `created_at` du projet) / (`target_date` − `created_at` du projet), 0, 1)
- pourcentage terminé tel que défini ci-dessus

Le statut est : `late` si la `target_date` est dépassée et que le pourcentage terminé est inférieur à 100 % ; sinon `at-risk` si le pourcentage terminé est strictement inférieur au pourcentage attendu à date ; sinon `ok`.

#### Scenario: Projet dans les temps
- **WHEN** un projet créé il y a 10 jours avec une `target_date` à 100 jours est terminé à 20 % (attendu à date : 10 %)
- **THEN** le statut de projection est `ok`

#### Scenario: Projet en risque
- **WHEN** un projet est terminé à 10 % alors que le pourcentage attendu à date est de 50 %
- **THEN** le statut de projection est `at-risk`

#### Scenario: Projet en retard
- **WHEN** la `target_date` d'un projet est passée et que 30 % de ses issues seulement sont terminées
- **THEN** le statut de projection est `late`

#### Scenario: Dépassement de la date cible avec projet terminé
- **WHEN** la `target_date` d'un projet est passée et que 100 % de ses issues sont terminées
- **THEN** le statut de projection est `ok`

### Requirement: Page Health par projet
Le système SHALL exposer une page Health par projet présentant l'ensemble des indicateurs ci-dessus : compteurs par catégorie, pourcentage terminé, issues en retard, ratio de consommation des heures, statut et détail de la projection de retard.

#### Scenario: Consultation de la page Health
- **WHEN** un membre ouvre la page Health d'un projet
- **THEN** la page affiche le nombre d'issues par catégorie, le pourcentage terminé, le nombre d'issues en retard, le ratio de consommation des heures et le statut de projection avec la date cible

### Requirement: Vue d'ensemble des projets
La liste des projets du workspace SHALL afficher pour chaque projet une vue d'ensemble de santé : statut de projection (ou son absence si pas de `target_date`) et pourcentage terminé.

#### Scenario: Badge de statut sur la liste
- **WHEN** un membre consulte la liste des projets et qu'un projet est `at-risk`
- **THEN** la liste affiche un badge "at-risk" et le pourcentage terminé de ce projet

### Requirement: Visibilité de la santé
Tout membre du workspace SHALL pouvoir consulter la santé de n'importe quel projet du workspace, sans restriction de rôle.

#### Scenario: Consultation par un membre simple
- **WHEN** un membre (rôle member) consulte la page Health d'un projet de son workspace
- **THEN** tous les indicateurs lui sont présentés

#### Scenario: Accès inter-workspace refusé
- **WHEN** un utilisateur tente d'accéder à la santé d'un projet appartenant à un workspace dont il n'est pas membre
- **THEN** l'accès est refusé

