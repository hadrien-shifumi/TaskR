## ADDED Requirements

### Requirement: Workspace creation
Le système SHALL permettre à tout utilisateur authentifié de créer un workspace dont il devient propriétaire (rôle `owner`). À la création, le workspace SHALL recevoir un jeu de statuts par défaut (Backlog, In Progress, Done, Cancelled).

#### Scenario: Création d'un premier workspace
- **WHEN** un utilisateur authentifié crée un workspace nommé "Studio"
- **THEN** le workspace existe avec l'utilisateur comme membre `owner`
- **AND** le workspace possède les 4 statuts par défaut, chacun rattaché à sa catégorie sémantique

### Requirement: Appartenance multi-workspace
Un utilisateur SHALL pouvoir être membre de plusieurs workspaces et changer de workspace actif dans l'UI. Toute donnée consultée SHALL appartenir exclusivement au workspace actif.

#### Scenario: Changement de workspace actif
- **WHEN** un utilisateur membre de deux workspaces bascule vers le second
- **THEN** les projets, issues, statuts et membres affichés sont uniquement ceux du second workspace

### Requirement: Invitation de membres
Un membre `owner` SHALL pouvoir inviter un utilisateur par adresse email. L'invité rejoint le workspace avec le rôle `member` après acceptation.

#### Scenario: Invitation acceptée
- **WHEN** un owner invite `bob@example.com` et que Bob accepte via le lien reçu
- **THEN** Bob est membre du workspace avec le rôle `member`
- **AND** Bob voit les projets et issues de ce workspace

#### Scenario: Invitation par un member refusée
- **WHEN** un utilisateur `member` tente d'inviter quelqu'un
- **THEN** l'action est refusée avec une erreur explicite

### Requirement: Rôles limités à owner et member
Le système SHALL fournir exactement deux rôles : `owner` (gestion des membres, des statuts, suppression du workspace) et `member` (usage courant). Il n'existera PAS de permissions granulaires.

#### Scenario: Suppression d'un statut réservée aux owners
- **WHEN** un `member` tente de supprimer un statut du workspace
- **THEN** l'action est refusée

### Requirement: Isolation inter-workspaces
Le système SHALL empêcher tout accès à une ressource appartenant à un workspace dont l'utilisateur n'est pas membre, y compris par identifiant direct.

#### Scenario: Accès à une issue d'un autre workspace
- **WHEN** un utilisateur demande l'issue X par son identifiant alors que X appartient à un workspace dont il n'est pas membre
- **THEN** la requête est refusée (404/403) et aucune donnée n'est exposée
