# Taskr — Brief de conception UI/UX pour maquettes

Document destiné au designer. Il décrit ce que fait Taskr, ses fonctionnalités (implémentées et à venir), les règles métier qui contraignent l'UI, et la liste des écrans à maquetter.

**Stack UI imposée : React 19 + Tailwind CSS v4 + composants ShadCN/ui** (sur Base UI). Les maquettes doivent pouvoir se traduire directement en composants ShadCN : `button`, `input`, `textarea`, `select`, `dropdown-menu`, `table`, `badge`, `dialog`, `label`, `card`. Merci de raisonner en ces briques (dialogs pour les formulaires, badges pour les statuts/priorités, table pour les listes) et d'utiliser les tokens Tailwind v4 (couleurs `muted`, `destructive`, etc.) plutôt que des couleurs arbitraires.

---

## 1. Le produit en une phrase

Taskr est un tracker de tickets multi-tenant minimaliste, organisé autour du **workspace** : des équipes créent des projets, y créent des issues, suivent leur statut, le temps passé et la santé des projets — sans sprints, sans workflow configurable.

**Positionnement / principes de conception :**
- Pilotage par convention : pas de sprints, pas de types d'issue (Epic/Story/Task), pas de workflow par projet. Le workspace impose ses statuts à tous les projets.
- Simplicité : un projet est un simple conteneur (nom + description). Toute la "machine" vit au niveau workspace.
- Conçu pour être piloté autant par des agents IA (MCP, à venir) que par des humains — d'où des identifiants opaques (ULID) dans les URLs, pas de numérotation séquentielle.
- Deux rôles seulement : `owner` et `member`. Pas de permissions granulaires.

## 2. Concepts du modèle (le vocabulaire à utiliser dans l'UI)

| Concept | Description |
|---|---|
| **Workspace** | Le tenant. Tout (projets, issues, statuts, labels, membres) appartient à un seul workspace. Un utilisateur peut être membre de plusieurs workspaces et basculer entre eux. |
| **Projet** | Conteneur minimal : nom, description optionnelle, date cible optionnelle. Peut être archivé (issues consultables) ; suppression définitive refusée s'il contient des issues. |
| **Issue** | Titre (requis), description **Markdown**, statut, priorité (`none` / `low` / `high` / `urgent` — 4 niveaux fixes), labels libres, **plusieurs assignés**, due date optionnelle, estimation en heures optionnelle. |
| **Hiérarchie molle** | Une issue peut avoir une issue parente (même workspace), profondeur illimitée, sans type. Cycles interdits. |
| **Statut** | Défini au niveau workspace : nom libre + **catégorie sémantique fixe** parmi `backlog` / `in_progress` / `done` / `cancelled`. Changement de statut libre (aucune transition imposée). 4 statuts par défaut créés avec le workspace : Backlog, In Progress, Done, Cancelled. |
| **Time entry** | Saisie de temps sur une issue : auteur, date travaillée, heures décimales (> 0), note optionnelle. Édition/suppression réservées à l'auteur. |
| **Santé projet** | Indicateurs *calculés* (jamais saisis) : avancement %, issues en retard, consommation des heures, projection `on track` / `at risk` / `late`. |

## 3. Règles métier qui contraignent l'UI (invariants à respecter dans les maquettes)

**Rôles et permissions**
- `owner` seul : gestion des membres (invitations), gestion des statuts (créer/renommer/réordonner/supprimer), (à venir : gabarits d'issues).
- `member` : tout le reste — projets, issues, commentaires, temps.
- Chaque commentaire / time entry : éditable ou supprimable **uniquement par son auteur** (prévoir l'affichage conditionnel des actions).

**Protections (messages et états d'erreur à maquetter)**
- Suppression d'un statut refusée tant que des issues l'utilisent → proposer la migration des issues vers un autre statut avant suppression.
- Suppression d'un projet refusée s'il contient des issues → proposer l'archivage.
- Rattachement parent refusé si cycle ou si le parent est d'un autre workspace.
- Isolation : aucune donnée d'un workspace dont l'utilisateur n'est pas membre n'est visible (même par URL directe).

**Comportements attendus**
- Changement de statut : toujours possible, sans étape intermédiaire (ex. Backlog → Done direct).
- Création d'issue minimale : un titre suffit ; statut par défaut et priorité `none` appliqués.
- Archivage d'un projet : disparaît de la liste active, mais issues consultables et désarchivage possible.
- Les labels sont libres (création à la volée), non un référentiel géré.
- Tout est en ULID dans les URLs (pas de "TASK-42").

## 4. Écrans existants (déjà implémentés — à redessiner)

### 4.1 Auth
- **Login** / **Inscription** (guest layout, centré). L'inscription crée le premier workspace de l'utilisateur avec les 4 statuts par défaut.
- **Acceptation d'invitation** : page/parcours où l'invité rejoint le workspace avec le rôle `member`.

### 4.2 Layout applicatif (à retravailler en priorité — c'est la coquille de tout)
- Sélecteur de **workspace actif** (l'utilisateur peut être membre de plusieurs).
- Navigation : Home, Projects, Issues, Settings (Statuts).
- Les données affichées appartiennent exclusivement au workspace actif.
- *La page Home actuelle est un placeholder* — opportunité de proposer un dashboard.

### 4.3 Projets — liste
- Liste des projets du workspace actif ; les projets archivés sont exclus de la liste active (accès aux archivés + désarchivage à prévoir).
- Création de projet (dialog : nom, description, date cible).
- Édition, archivage, suppression (avec la protection décrite plus haut).
- **Vue d'ensemble santé** : chaque projet affiche un badge de projection (`On track` / `At risk` / `Late` / pas de projection) + pourcentage terminé.

### 4.4 Projet — page Health
- En-tête projet (nom, description, date cible) + badge de projection.
- Indicateurs (cards) :
  - Répartition des issues par catégorie (`backlog` / `in_progress` / `done` / `cancelled`) ;
  - % terminé = (done + cancelled) / total ;
  - Nombre d'issues en retard (due date dépassée, hors done/cancelled) ;
  - Consommation des heures : logguées / estimées ;
  - Projection : comparaison du % terminé au % attendu à date (selon la date cible).
- Accessible à tout membre du workspace.

### 4.5 Issues — liste
- Liste **tous projets confondus** du workspace.
- Filtres minimum : projet, statut (et catégorie), priorité, labels, assigné, "due date dépassée".
- Création d'issue : titre requis ; description Markdown, statut, priorité, labels, assignés, due date, projet, estimation optionnels.
- Colonnes suggérées : titre, statut (badge coloré par catégorie), priorité, labels, assignés (avatars empilés), due date (surlignée si dépassée).

### 4.6 Issue — détail
- Titre + description Markdown (rendu + édition, éditeur markdown dispo en composant).
- **Changement de statut** : sélecteur libre, aucune contrainte de transition.
- **Assignés multiples** : ajout/retrait indépendant de chaque assigné.
- **Labels** : ajout/retrait libre.
- **Parent/enfants** : rattachement à une issue parente (hiérarchie molle, profondeur libre) ; affichage des enfants ; erreurs de cycle à prévoir.
- **Due date** + estimation d'heures (affichage + dialog d'édition).
- **Section Time** : totaux `loggué / estimé / restant`, liste des entrées (auteur, date, heures, note), formulaire d'ajout (heures + note + date), edit/delete sur ses propres entrées uniquement.
- **Commentaires** : fil chronologique (auteur, corps Markdown, horodatage) ; édition/suppression par l'auteur seulement.
- Suppression de l'issue.

### 4.7 Settings — Statuts
- Liste ordonnée des statuts du workspace (nom + catégorie).
- Création (nom libre + catégorie parmi les 4), renommage, **réordonnancement** (drag à prévoir) — l'ordre s'applique partout dans l'UI.
- Suppression protégée : refusée tant que des issues utilisent le statut, avec message indiquant le nombre d'issues et invitation à les migrer.
- Réservé aux `owner` (prévoir l'état "lecture seule" pour les members).
- La **catégorie sémantique** doit être visible à côté du nom (c'est elle qui porte la couleur/sens, le nom étant libre).

## 5. Fonctionnalités à venir (changes OpenSpec planifiées — à maquetter ou à réserver)

- **Gabarits d'issues** (`templates`) : page Settings/Templates (CRUD owner only : nom unique par workspace, corps Markdown, priorité et labels par défaut) + sélecteur « Template » optionnel dans le dialogue de création d'issue, qui **pré-remplit** sans contraindre (aucun lien persistant issue ↔ gabarit).
- **API tokens / MCP** (`mcp-access`) : page Settings / API tokens (création, révocation ; valeur du token visible une seule fois) — Taskr est pilotable par des agents IA.
- *(Hors périmètre confirmé : sprints, vélocité, burndown charts, alertes/notifications, permissions granulaires, workflow par projet.)*

## 6. Checklist designer

1. Design system sur base **ShadCN/ui + Tailwind v4** (tokens de couleurs sémantiques : `muted`, `destructive`, etc.).
2. États conditionnels par rôle : actions owner-only masquées/désactivées pour `member` ; actions auteur-only sur commentaires et time entries.
3. États d'erreur fonctionnels : suppression protégée (statut, projet), cycle de parenté, formulaires (titre requis).
4. Code couleur des **catégories sémantiques** (backlog / in_progress / done / cancelled) et des **priorités** (none / low / high / urgent), badges de projection (ok / at-risk / late).
5. Responsive desktop + mobile (l'app est testée E2E dans les deux viewports).
6. Identifiants opaques (ULID) dans les URLs — ne pas maquetter de numéros de tickets lisibles.
