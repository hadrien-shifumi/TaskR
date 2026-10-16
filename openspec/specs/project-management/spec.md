
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

### Requirement: Roster projet (rôles projet)
Chaque projet SHALL posséder un roster de membres avec trois rôles possibles : `owner` (créateur du projet par défaut, unique par projet), `manager` (multiples, peut gérer le roster) et `member` (membre du roster sans droit de gestion). L'`owner` du workspace est traité comme super-manager sur tous les projets.

#### Scenario: Création de projet avec auto-onboard
- **WHEN** un membre crée un projet
- **THEN** il devient automatiquement `owner` du projet (roster)

#### Scenario: Gestion du roster réservée aux managers
- **WHEN** un `member` du roster (non manager) tente d'ajouter ou retirer un membre
- **THEN** l'action est refusée (403)
- **AND** un `owner` projet, `manager` projet ou `owner` workspace peut effectuer l'opération

#### Scenario: Transfert d'ownership
- **WHEN** un `owner` projet transfère son rôle à un autre membre
- **THEN** le nouveau devient `owner` et l'ancien devient `member`
- **AND** il n'existe jamais deux `owner` simultanément

#### Scenario: Membre du roster = membre du workspace
- **WHEN** on tente d'ajouter au roster un utilisateur qui n'est pas membre du workspace
- **THEN** la validation échoue

### Requirement: Roster sans contrôle d'accès
Le roster projet ne modifie PAS la visibilité des projets : tout membre du workspace peut voir et opérer sur tous les projets. Le roster pilote uniquement le filtre assignee, la charge de travail et la visibilité des indicateurs de santé (voir spec `project-health`).
