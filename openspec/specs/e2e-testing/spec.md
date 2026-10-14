# e2e-testing Specification

## Purpose
Tests end-to-end navigateur (Pest browser + Playwright/Chromium, écrits en PHP dans `tests/Browser`) sécurisant les parcours utilisateurs de bout en bout : rendu réel des pages React/Inertia, navigation client, dialogs, erreurs JavaScript, logs console et accessibilité — défauts invisibles pour les feature tests.
## Requirements
### Requirement: Socle de tests navigateur
Le système de test SHALL exécuter des tests end-to-end dans un navigateur réel (Chromium via Playwright), écrits en PHP dans une testsuite dédiée (`tests/Browser`), avec accès aux helpers Laravel : base fraîche par test, factories, actions du domaine et fakes. La suite de tests existante SHALL continuer de passer sans modification d'aucun fichier de test.

#### Scenario: Smoke de la page de login
- **WHEN** la suite navigateur visite la page `/login`
- **THEN** le formulaire de connexion s'affiche, sans erreur JavaScript ni log console

#### Scenario: Données préparées par le domaine, visibles dans le navigateur
- **WHEN** un test navigateur prépare ses données via les actions du domaine et les factories
- **THEN** ces données sont visibles dans le navigateur au cours du même test, et aucun état ne fuite d'un test au suivant

### Requirement: Parcours golden path de bout en bout
La suite navigateur SHALL couvrir le parcours utilisateur complet : inscription, connexion, création de projet, création d'issue minimale, changement de statut direct et commentaire — chaque étape étant validée via l'interface réelle.

#### Scenario: Inscription puis première issue
- **WHEN** un visiteur s'inscrit via le formulaire, crée un projet, puis crée une issue avec son seul titre
- **THEN** il est authentifié, le workspace possède les 4 statuts par défaut, et l'issue apparaît au statut Backlog dans la liste avec un détail consultable

#### Scenario: Cycle d'une issue en navigateur
- **WHEN** l'utilisateur ouvre le détail d'une issue, la passe directement à Done, puis ajoute un commentaire
- **THEN** le statut et le commentaire sont reflétés dans l'interface sans erreur JavaScript

### Requirement: Parcours management et collaboration
La suite navigateur SHALL couvrir en session réelle : la gestion des statuts par un owner (dont suppression protégée), l'invitation suivie de son acceptation, la bascule de workspace actif, et la saisie de temps depuis l'interface.

#### Scenario: Gestion des statuts par un owner
- **WHEN** un owner crée le statut "En revue", le réordonne, puis tente de le supprimer alors que des issues l'utilisent
- **THEN** le nouvel ordre est reflété dans l'UI et la suppression est refusée avec un message indiquant le nombre d'issues concernées

#### Scenario: Invitation et multi-workspace
- **WHEN** un owner invite Bob par email et que Bob accepte via le lien de l'invitation
- **THEN** Bob est membre du workspace avec le rôle member, en voit les projets et issues, et la bascule entre ses workspaces n'expose que les données du workspace actif

#### Scenario: Saisie de temps depuis l'interface
- **WHEN** un membre saisit une entrée avec ses heures et une note depuis la section Time du détail d'une issue
- **THEN** l'entrée apparaît dans la liste et les totaux logguées / estimées / restantes sont mis à jour

### Requirement: Santé projet en navigateur
La suite navigateur SHALL vérifier la page Health et les badges de la liste des projets avec des données déterministes : issues dans chaque catégorie de statut, due dates et target date contrôlées.

#### Scenario: Page Health avec données ciblées
- **WHEN** un membre ouvre la page Health d'un projet contenant des issues dans chaque catégorie et une target date dépassée
- **THEN** la page affiche les compteurs par catégorie, le pourcentage terminé, les issues en retard, le ratio de consommation et le statut de projection

### Requirement: Smoke global des pages
Chaque page de l'application SHALL être visitée par la suite navigateur sans erreur JavaScript, sans log console, ni problème d'accessibilité de niveau serious ou supérieur, en viewport desktop et mobile.

#### Scenario: Toutes les pages sans fumée
- **WHEN** la suite visite toutes les pages existantes de l'application, en viewport desktop puis mobile
- **THEN** aucune erreur JavaScript, aucun log console et aucune violation d'accessibilité serious ne sont détectés

