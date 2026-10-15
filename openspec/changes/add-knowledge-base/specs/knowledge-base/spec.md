## ADDED Requirements

### Requirement: CRUD pages de connaissance
Le système SHALL permettre aux membres d'un workspace de créer, éditer et supprimer des pages de connaissance : titre, corps Markdown, parent optionnel (hiérarchie arborescente), projet optionnel du même workspace. L'auteur de la dernière édition et l'horodatage de modification SHALL être enregistrés. Toute page appartient à un seul workspace et l'isolation multi-tenant s'applique (accès refusé par identifiant direct depuis un autre workspace).

#### Scenario: Création d'une page transverse au workspace
- **WHEN** un membre crée une page "Glossaire métier" sans projet ni parent
- **THEN** la page apparaît à la racine de l'arborescence Knowledge du workspace, éditable par tout membre

#### Scenario: Création d'une page rattachée à un projet
- **WHEN** un membre crée une page "Architecture du module facturation" rattachée au projet "Facturation"
- **THEN** la page est associée à ce projet et apparaît dans l'arborescence et la recherche du workspace, filtrable par projet

#### Scenario: Rattachement à un projet d'un autre workspace refusé
- **WHEN** un membre crée une page en ciblant un projet n'appartenant pas au workspace courant
- **THEN** la création est refusée avec une erreur de validation

#### Scenario: Édition collaborative
- **WHEN** un membre autre que l'auteur initial édite une page
- **THEN** l'édition est enregistrée et le dernier éditeur est reflété sur la page

#### Scenario: Accès cross-workspace refusé
- **WHEN** un utilisateur demande une page par son identifiant alors qu'elle appartient à un workspace dont il n'est pas membre
- **THEN** la requête est refusée (404/403) et aucune donnée n'est exposée

### Requirement: Hiérarchie arborescente sans cycle
Le système SHALL permettre d'organiser les pages en arborescence parent/enfant. Un parent SHALL appartenir au même workspace que sa page enfant, et la définition d'un parent SHALL être refusée si elle crée un cycle (une page ne peut pas être son propre ancêtre). La suppression d'une page ayant des enfants SHALL être refusée ; les enfants sont déplacés ou supprimés au préalable.

#### Scenario: Refus de cycle
- **WHEN** un membre définit la page A comme parent de la page B alors que B est déjà ancêtre de A
- **THEN** l'action est refusée avec une erreur explicite et la hiérarchie est inchangée

#### Scenario: Suppression refusée avec enfants
- **WHEN** un membre tente de supprimer une page ayant 2 pages enfants
- **THEN** la suppression est refusée avec un message invitant à déplacer ou supprimer les enfants d'abord

#### Scenario: Déplacement dans l'arborescence
- **WHEN** un membre change le parent d'une page pour une page du même workspace sans créer de cycle
- **THEN** la page et sa descendance apparaissent sous le nouveau parent

### Requirement: Recherche sémantique des pages
Le système SHALL exposer une recherche par similarité sémantique : la requête et les pages sont vectorisées par embeddings, et les résultats sont classés par similarité cosinus décroissante, restreints au workspace courant et filtrables par projet. Chaque résultat SHALL inclure l'identifiant, le titre, un extrait du contenu et le score de similarité. Les pages non encore vectorisées SHALL être exclues des résultats de recherche tout en restant consultables dans l'arborescence.

#### Scenario: Recherche par sens plutôt que par mot-clé exact
- **WHEN** un membre recherche "comment se passe la facturation d'un client" et qu'une page "Processus de règlement et invoices" existe sans contenir ces mots exacts
- **THEN** la page est retournée parmi les meilleurs résultats grâce à la similarité d'embeddings

#### Scenario: Recherche restreinte au workspace courant
- **WHEN** un membre recherche dans le workspace A
- **THEN** seules les pages du workspace A sont retournées, quel que soit leur score de similarité avec des pages d'autres workspaces

#### Scenario: Filtre par projet
- **WHEN** un membre restreint la recherche à un projet
- **THEN** seules les pages rattachées à ce projet sont retournées

#### Scenario: Page pas encore vectorisée
- **WHEN** une page vient d'être créée et sa vectorisation est encore en attente
- **THEN** elle n'apparaît pas dans les résultats de recherche mais reste visible dans l'arborescence

### Requirement: Pipeline de vectorisation à l'écriture
À la création et à chaque édition d'une page, le système SHALL re-vectoriser la page (titre + corps) de façon asynchrone via un endpoint d'embeddings OpenAI-compatible configurable (base URL, modèle, clé). Un échec de l'endpoint SHALL être retenté sans bloquer l'écriture ni la consultation de la page. Si aucun endpoint n'est configuré, l'écriture et la navigation SHALL fonctionner et la recherche sémantique SHALL retourner une erreur explicite de configuration plutôt qu'un résultat silencieusement dégradé.

#### Scenario: Édition puis recherche à jour
- **WHEN** une page est éditée et que sa re-vectorisation aboutit
- **THEN** une recherche ultérieure classe la page selon son nouveau contenu

#### Scenario: Échec de l'endpoint d'embeddings
- **WHEN** l'endpoint d'embeddings est indisponible lors de l'édition d'une page
- **THEN** l'édition est enregistrée, la page reste consultable, et la vectorisation est retentée automatiquement

#### Scenario: Aucun endpoint configuré
- **WHEN** la recherche sémantique est appelée sans configuration d'embeddings
- **THEN** une erreur de configuration explicite est retournée ; création, édition et navigation continuent de fonctionner

### Requirement: Navigation utilisateur de la base de connaissance
Le système SHALL fournir une section Knowledge par workspace : arborescence des pages (transverses et par projet), lecture avec rendu Markdown sécurisé, recherche sémantique, et éditeur Markdown. La consultation est ouverte à tout membre du workspace.

#### Scenario: Prise de connaissance par un nouvel arrivant
- **WHEN** un nouveau membre ouvre la section Knowledge du workspace
- **THEN** il voit l'arborescence complète des pages, les ouvre une à une et peut chercher par descripteur naturel

#### Scenario: Rendu Markdown sécurisé
- **WHEN** une page contient du Markdown incluant du HTML ou un script
- **THEN** le contenu est rendu en Markdown sans exécuter ni injecter de HTML non échappé
