# time-tracking Specification

## Purpose
Suivi de l'effort réel sur les issues : estimation horaire grossière (`estimated_hours`) et time entries (auteur, date travaillée, heures décimales strictement positives, note optionnelle). Le détail d'une issue expose les totaux logged / estimated / remaining, mettant en évidence la surconsommation.
## Requirements
### Requirement: Estimation d'une issue
Chaque issue MAY porter une estimation en heures décimales (`estimated_hours`), nullable, éditable par tout membre du workspace. Le système SHALL accepter les valeurs nulles (pas d'estimation) et numériques positives, et SHALL rejeter les valeurs négatives.

#### Scenario: Définir une estimation
- **WHEN** un membre définit l'estimation "3,5 heures" sur une issue
- **THEN** l'issue affiche 3,5 h comme estimation et le restant est recalculé

#### Scenario: Réinitialiser une estimation
- **WHEN** un membre supprime l'estimation d'une issue estimée
- **THEN** l'issue n'affiche plus d'estimation et le restant n'est plus calculable

#### Scenario: Valeur négative rejetée
- **WHEN** un membre soumet une estimation de -2 heures
- **THEN** l'action est refusée avec une erreur de validation

### Requirement: Saisie de temps sur une issue
Le système SHALL permettre à tout membre du workspace de logger du temps sur une issue : heures décimales strictement positives, date du jour travaillé, note optionnelle. L'auteur de la saisie est le membre qui la crée.

#### Scenario: Logging d'une demi-journée
- **WHEN** un membre loggue 4 heures sur une issue, avec la note "setup CI"
- **THEN** l'entrée apparaît dans la section temps de l'issue avec son auteur, la date et la note

#### Scenario: Heures nulles rejetées
- **WHEN** un membre soumet une saisie de 0 heure (ou négative)
- **THEN** l'action est refusée avec une erreur de validation

#### Scenario: Date de travail par défaut
- **WHEN** un membre ajoute une entrée sans préciser de date
- **THEN** la date du jour est utilisée comme date travaillée

#### Scenario: Issue d'un autre workspace rejetée
- **WHEN** un membre tente de logger du temps sur une issue d'un autre workspace
- **THEN** l'action est refusée

### Requirement: Gestion de ses entrées de temps
Une entrée de temps SHALL être éditable et supprimable par son auteur uniquement, comme un commentaire. Toute autre utilisateur, même membre du workspace, SHALL se voir refuser l'édition et la suppression.

#### Scenario: Édition par l'auteur
- **WHEN** l'auteur d'une entrée corrige ses heures de 2 à 3
- **THEN** l'entrée affiche 3 heures et les totaux de l'issue sont recalculés

#### Scenario: Édition par un tiers refusée
- **WHEN** un autre membre tente d'éditer l'entrée de quelqu'un d'autre
- **THEN** l'action est refusée

#### Scenario: Suppression par un tiers refusée
- **WHEN** un autre membre tente de supprimer l'entrée de quelqu'un d'autre
- **THEN** l'action est refusée

### Requirement: Totaux de temps sur l'issue
Le système SHALL exposer pour chaque issue trois totaux : heures logguées (somme des entrées, 0 si aucune), heures estimées (estimation ou absence), et restant (estimé − loggué). Le restant n'est pas stocké et MAY être négatif (surconsommation) ; il n'est pas calculable sans estimation.

#### Scenario: Totaux après plusieurs saisies
- **WHEN** une issue estimée à 8 h comporte deux entrées de 2 h et 3 h
- **THEN** la vue détail affiche 5 h logguées, 8 h estimées, 3 h restantes

#### Scenario: Surconsommation visible
- **WHEN** une issue estimée à 5 h totalise 7 h logguées
- **THEN** le restant affiché est -2 h

#### Scenario: Issue sans estimation
- **WHEN** une issue sans estimation totalise 4 h logguées
- **THEN** la vue détail affiche 4 h logguées et aucune estimation ni restant

### Requirement: Affichage du temps dans la vue détail issue
La vue détail d'une issue SHALL présenter une section "Time" visible par tous les membres du workspace : liste des entrées (auteur, date, heures, note), formulaire d'ajout (heures + note + date), actions edit/delete uniquement sur ses propres entrées, édition de l'estimation via dialog, et affichage "logged / estimated".

#### Scenario: Lecture par un membre quelconque
- **WHEN** un membre consulte le détail d'une issue contenant des entrées de plusieurs auteurs
- **THEN** il voit toutes les entrées (auteur, date, heures, note) et ne dispose d'aucune action d'édition sur celles des autres

#### Scenario: Ajout depuis le détail de l'issue
- **WHEN** un membre saisit 1,5 h avec la note "revue de code" depuis le formulaire de la section "Time"
- **THEN** l'entrée apparaît dans la liste et les totaux sont mis à jour

