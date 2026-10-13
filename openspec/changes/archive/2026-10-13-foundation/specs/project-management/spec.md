## ADDED Requirements

### Requirement: CRUD projet
Le système SHALL permettre aux membres d'un workspace de créer, renommer et archiver des projets. Un projet est un conteneur minimal : nom, description optionnelle, membership au workspace.

#### Scenario: Création d'un projet
- **WHEN** un membre crée un projet "Refonte site vitrine"
- **THEN** le projet apparaît dans la liste des projets du workspace, sans statuts ni configuration propre

#### Scenario: Archivage d'un projet
- **WHEN** un membre archive un projet contenant des issues
- **THEN** le projet n'apparaît plus dans la liste active mais ses issues restent consultables et le projet peut être désarchivé

### Requirement: Projet sans workflow propre
Un projet n'introduira PAS de statuts, de configuration ou de workflow qui lui soient propres : les statuts et conventions vivent exclusivement au niveau workspace, et le système SHALL appliquer les statuts du workspace à toutes les issues de tous les projets.

#### Scenario: Les statuts du workspace s'appliquent à tous les projets
- **WHEN** un owner renomme un statut du workspace
- **THEN** toutes les issues de tous les projets du workspace reflètent le nouveau nom

### Requirement: Suppression de projet conditionnée
La suppression définitive d'un projet SHALL être refusée tant qu'il contient des issues ; l'archivage est l'alternative proposée.

#### Scenario: Suppression refusée
- **WHEN** un membre tente de supprimer un projet contenant 3 issues
- **THEN** l'action est refusée avec un message invitant à archiver
