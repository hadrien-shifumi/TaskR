## ADDED Requirements

### Requirement: Design tokens de l'interface
L'interface SHALL utiliser exclusivement les tokens oklch des maquettes (`docs/maquettes/assets/app.css`), mappés sur les slots sémantiques du thème Tailwind v4 existants : `--bg` → background, `--surface` → card/popover, `--fg` → foreground, `--muted` → muted-foreground, `--border` → border/input, `--accent` → primary/ring. Les couleurs `warning`, `success`, `info` SHALL être exposées comme tokens sémantiques du thème. Aucune couleur brute (hex/oklch littéral) SHALL apparaître dans le JSX des pages.

#### Scenario: Palette identique aux maquettes
- **WHEN** on compare les valeurs de `:root` de `resources/css/app.css` au bloc tokens de `assets/app.css`
- **THEN** chaque token maquette se retrouve, verbatim ou mappé sur son slot sémantique, et les écrans existants héritent du nouveau look sans changement de leur JSX

#### Scenario: Badges sémantiques
- **WHEN** une carte d'issue affiche priorité, catégorie de statut ou retard
- **THEN** les couleurs viennent des tokens sémantiques (`--warning`, `--success`, `--info`, `--destructive`), pas de valeurs codées en dur

### Requirement: Inventaire des écrans et de leurs routes
Chaque écran maquetté SHALL exister dans l'application à sa route : connexion/inscription/invitation (Auth), dashboard workspace (`/`), liste projets (`/projects`), santé projet (`/projects/{project}/health`), board kanban (`/projects/{project}/board`), issues cross-projet (`/issues`), détail issue (`/issues/{issue}`), réglages (`/settings/*`). Chaque écran SHALL être alimenté par les vraies données via props Inertia, jamais par des données de démo.

#### Scenario: Couverture des maquettes
- **WHEN** on passe en revue les écrans de `docs/maquettes/screens/`
- **THEN** chacun correspond à une page existante atteignable par navigation (hors `index.html`, launcher non implémentable, et écrans « à venir » explicitement hors périmètre)

#### Scenario: Données réelles
- **WHEN** un utilisateur ouvre un écran
- **THEN** toutes les listes, compteurs et identifiants proviennent des responders Inertia (ULID réels, compteurs cohérents entre écrans)

### Requirement: Board kanban par projet
Le système SHALL fournir un board kanban par projet à `/projects/{project}/board` : une colonne par statut du workspace dans l'ordre du workspace, les issues du projet groupées par statut, un compteur par colonne et un état vide explicite. Le déplacement d'une carte par drag & drop SHALL passer par la route existante `issues.move` (action `MoveIssue`) ; en cas de refus, la carte SHALL revenir à sa colonne d'origine avec le message d'erreur. Une alternative clavier (« déplacer vers ») SHALL être disponible sur chaque carte.

#### Scenario: Déplacement d'une carte
- **WHEN** un membre dépose une carte sur une autre colonne
- **THEN** la route `issues.move` est appelée avec le statut cible et l'ordre, et le board reflète le déplacement après la réponse

#### Scenario: Refus de déplacement
- **WHEN** l'action `MoveIssue` refuse le déplacement (erreur métier ou d'autorisation)
- **THEN** la carte retourne dans sa colonne d'origine et le message d'erreur est visible

#### Scenario: Cohérence avec la santé projet
- **WHEN** on compare les compteurs du board aux répartitions de la santé projet pour le même projet
- **THEN** les nombres concordent (mêmes issues, mêmes statuts)

### Requirement: Réglages multi-sections avec lecture seule member
Les réglages SHALL présenter les sections Statuts, Membres, Gabarits et l'emplacement réservé « Tokens API », chacune à sa route derrière un shell commun. Une section Membres SHALL lister les membres avec leur rôle et permettre l'invitation par email et le retrait d'un membre (actions owner). Un `member` SHALL voir les réglages en lecture seule : contrôles owner-only masqués ou désactivés, avec un indicateur de lecture seule ; les refus backend restent la barrière effective.

#### Scenario: Vue owner
- **WHEN** un owner ouvre les réglages de son workspace
- **THEN** il accède aux quatre sections et peut inviter un membre, qui rejoint le workspace en tant que `member` après acceptation

#### Scenario: Vue member en lecture seule
- **WHEN** un member ouvre les réglages
- **THEN** il consulte les sections mais aucun contrôle owner-only n'est actionnable, et toute tentative directe (hors UI) est refusée par les règles existantes

### Requirement: Présentation UI des règles métier
L'interface SHALL rendre visibles les invariants existants sans les réimplémenter : la suppression d'un statut utilisé propose la migration préalable des issues vers un autre statut (dialog) et affiche le refus de l'action si l'état a changé ; la suppression d'un projet contenant des issues affiche le refus et propose l'archivage ; les actions d'édition/suppression d'un commentaire ne sont rendues qu'à son auteur ; le titre d'issue est validé inline (champ requis, erreur sous le champ) ; l'erreur de cycle du rattachement parent est affichée au niveau du sélecteur de parent.

#### Scenario: Migration de statut avant suppression
- **WHEN** un owner supprime un statut utilisé par des issues
- **THEN** le dialog exige le statut de destination des issues, la migration est proposée, et un refus de l'action (état changé entre-temps) s'affiche dans le dialog

#### Scenario: Suppression de projet refusée vers l'archivage
- **WHEN** un membre tente de supprimer un projet contenant des issues
- **THEN** le refus de l'action est affiché avec un chemin d'action vers l'archivage

#### Scenario: Commentaires réservés à l'auteur
- **WHEN** deux utilisateurs consultent le même fil de commentaires
- **THEN** seuls les commentaires de l'utilisateur connecté portent les contrôles éditer/supprimer

#### Scenario: Validation inline et erreur de cycle
- **WHEN** une issue est créée sans titre, ou qu'un parent créerait un cycle
- **THEN** l'erreur apparaît inline sous le champ concerné (titre) ou au niveau du sélecteur de parent (cycle), sans jamais dupliquer la règle côté client au-delà de l'affichage

### Requirement: Conventions de composants
L'interface SHALL être construite avec les primitives shadcn/ui existantes (`components/ui/*`, base UI) selon le mapping du handoff (`.btn` → button, `.card` → card, `.badge` → badge, dialog, `.data-table` → table, `.tabs` → tabs, `.field` → label + input). Tout nouveau primitif SHALL être ajouté via le CLI shadcn et rester piloté par les tokens. Les états vides SHALL avoir un message explicite et les cellules vides SHALL rendre `—`.

#### Scenario: Pas de second système de composants
- **WHEN** une page est créée ou reprise
- **THEN** elle n'importe que `components/ui/*` et les tokens Tailwind — aucune classe `.btn`/`.card` des maquettes ni feuille CSS additionnelle

#### Scenario: Nouveau primitif nécessaire
- **WHEN** un écran requiert un composant absent (ex. zone de drag & drop)
- **THEN** il est ajouté par le CLI shadcn (base UI, tokens) ou réalisé en primitifs natifs du navigateur sans dépendance nouvelle non validée
