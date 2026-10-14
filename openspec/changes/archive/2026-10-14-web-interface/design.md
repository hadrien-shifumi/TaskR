## Context

Les maquettes vivent dans `docs/maquettes/` : un écran par fichier dans `screens/` (login, home, projects, project-health, project-board, issues, issue-detail, settings), styles et scripts partagés dans `assets/app.css` (617 lignes : tokens + composants) et `assets/app.js` (266 lignes : kanban drag & drop, filtres, dialogs, validation), `HANDOFF.md` pour le mapping écran → route et les règles métier, `index.html` launcher documentant le langage visuel.

Côté application : stack Inertia v3 + React 19 + Tailwind v4 + shadcn sur `@base-ui/react` (pas Radix), pages dans `resources/js/Pages/<Domain>/`, responders `Inertia::render` par page, données uniquement via props Inertia. Les écrans Home, Projects/Index, Projects/Health, Issues/Index, Issues/Show, Settings/Statuses, Settings/Templates, Auth existent déjà. `resources/css/app.css` porte la palette shadcn par défaut (zinc neutre) — l'identité oklch des maquettes (accent bleu `oklch(58% 0.18 255)`, hairlines, rayons 8/12 px) n'y est pas reportée. Il manque le board kanban et la page membres ; la route d'invitation (`invitations.accept`) existe sans écran dédié.

Tous les invariants métier joués par les maquettes (édition commentaire auteur-only, suppression de statut refusée tant qu'utilisée, suppression de projet refusée → archivage, cycle de parenté, titre requis, owner-only réglages) sont déjà exigés par les specs `issue-tracking`, `status-management`, `project-management`, `workspace-management`. Rien à changer côté métier : cette change est purement présentationnelle plus deux écrans de lecture.

## Goals / Non-Goals

**Goals:**

- L'application ressemble aux maquettes : palette oklch verbatim, typographie (display/body system sans, mono tabulaire ULID/numériques), rayons, hairlines, densité.
- Tous les écrans maquettés existent et sont atteignables aux routes du handoff, alimentés par les vraies données.
- Les règles métier sont *visibles* là où les maquettes les montrent : dialogs de migration/refus, validation inline, contrôles conditionnés au rôle et à l'auteur.
- Livraison écran par écran, chaque écran restant consultable dans le navigateur comme référence (`screens/*.html` indépendants).

**Non-Goals:**

- Porter le HTML/CSS/JS des maquettes tel quel — ce sont des maquettes statiques avec données de démo, pas du code à intégrer.
- Toute évolution métier, de schéma ou d'API ; les actions d'écriture existantes ne bougent pas.
- Mode sombre repensé (les tokens maquette décrivent le thème clair ; décision en Open Questions).
- Tokens API et gabarits fonctionnels (périmètre `mcp-access` / « à venir ») — seuls leurs emplacements dans les réglages sont réservés.

## Decisions

### ADR-1 : Tokens — mapper la palette maquette sur les slots sémantiques shadcn, pas importer les classes

**Décision** : reporter les valeurs oklch de `assets/app.css` (bloc `:root`, section 3 du handoff) dans `resources/css/app.css` en les mappant sur les slots existants que le code consomme déjà : `--background` ← `--bg`, `--card`/`--popover` ← `--surface`, `--foreground` ← `--fg`, `--muted-foreground` ← `--muted`, `--border`/`--input` ← `--border`, `--primary` (et `--ring`) ← `--accent` (le bleu des maquettes est l'accent d'action, sémantiquement le primary shadcn), `--destructive` inchangé de rôle. Les dérivés `color-mix(in oklch, …)` des maquettes deviennent les valeurs des slots dérivés (`--secondary`, `--accent`, `--muted`). Ajout de trois tokens d'extension `--warning`, `--success`, `--info` exposés au `@theme inline` (badges de catégorie/priorité/retard) — extension du thème, pas d'un second système. Rayons : `--radius` reste le rayon maître (12 px) avec le 8 px pour les petits éléments via `rounded-lg` existant.

**Alternatives rejetées** : importer `app.css` des maquettes comme feuille additionnelle (deux sources de vérité, classes `.btn`/`.card` en concurrence avec shadcn) ; garder la palette zinc et ne changer que les écrans nouveaux (divergence visuelle immédiate entre anciens et nouveaux écrans).

**Justification** : la règle projet « Tailwind design tokens only, pas de couleur brute » est conservée intacte — seules les *valeurs* des tokens changent. Tous les écrans existants héritent du nouveau look sans toucher à leur JSX, et le mapping maquette → composant du handoff (section 4) reste un-pour-un.

### ADR-2 : Les maquettes sont une spec, pas du code à porter

**Décision** : chaque page reste une page Inertia (default export, `AppLayout`/`GuestLayout`, props typées localement, `useForm`, routes ziggy). Les maquettes servent de référence visuelle et d'ordre d'affichage ; `assets/app.js` décrit les *interactions attendues* (drag & drop, filtres, dialogs, validation) reproduites avec les primitives shadcn et les routes existantes. Les données de démo (« Studio Nova », projet Stripe, 18 issues aux compteurs cohérents) servent de jeu de vérification pour les requêtes, pas de fixtures.

**Justification** : porter le HTML recréerait une seconde couche de composants en concurrence avec `components/ui/*` et casserait le contrat Inertia testé par `assertInertia`. Le handoff le dit explicitement : « spec exécutable, pas du code à porter tel quel ».

### ADR-3 : Board kanban — DnD natif du navigateur, mouvement via `issues.move`, aucune nouvelle dépendance

**Décision** : le board est une nouvelle page `Pages/Projects/Board.tsx` rendue par un responder `ShowProjectBoard` (route `GET projects/{project}/board`, nommée `projects.board`) : issues groupées par statut du workspace dans l'ordre du workspace, compteurs par colonne, état vide par colonne. Le drag & drop utilise l'API native HTML5 (draggable + events) — aucune bibliothèque. Le drop n'est pas optimiste : il appelle la route existante `issues.move` (action `MoveIssue`, qui porte statut + ordre) et se synchronise sur la réponse ; en cas de refus métier, la carte revient à sa colonne d'origine avec le message d'erreur. Alternative au drag pour l'accessibilité : la carte garde un menu « déplacer vers » (select) équivalent.

**Alternatives rejetées** : `@dnd-kit` (nouvelle dépendance à faire valider, pour un kanban à colonnes entières sans multi-sélection ni animations complexes — le natif suffit ; à réévaluer si les interactions deviennent riches) ; un mouvement optimiste local (réordonnancement client + rattrapage : complexité de reconciliation pour un bénéfice de réactivité minime à cette échelle, et le drop rate reste le cas intéressant).

**Justification** : zéro dépendance nouvelle (règle projet), réutilisation de `MoveIssue` — aucune duplication du seul invariant réellement en jeu (statut + ordre dans le même workspace).

### ADR-4 : Réglages — sections en routes séparées derrière un shell commun, pas une page à onglets unique

**Décision** : un composant shell `Settings` (tabs visuels : Statuts, Membres, Gabarits, Tokens API) partagé par les pages `Settings/Statuses`, `Settings/Members`, `Settings/Templates` (+ futur onglet tokens), chaque onglet restant sa route et son responder. Nouvelle page `Settings/Members` : liste des membres (nom, email, rôle), invitation par email via l'action existante, retrait de membre owner-only. Un `member` voit le shell avec les actions owner-only masquées/désactivées et un indicateur « lecture seule » — le backend reste la barrière (les refus `status-management`/`workspace-management` ne changent pas).

**Alternatives rejetées** : une page unique à onglets avec toutes les données (un responder géant, des props croisées entre domaines, des soumissions qui re-rendent tout) ; des sections sans navigation commune (perd le modèle mental « réglages » des maquettes).

**Justification** : suit le pattern existant (un responder par page, props typées par page) ; l'onglet tokens API de `mcp-access` s'ajoutera plus tard sans refonte. La lecture seule member est une décision d'affichage dérivée des props partagées (`auth`, rôle du workspace), pas une nouvelle règle d'autorisation.

### ADR-5 : Présentation des règles métier — refléter les refus, ne pas les devancer

**Décision** : l'UI rend les invariants visibles comme dans les maquettes, sans réimplémenter la règle : dialog de suppression de statut exigeant le statut de migration quand des issues l'utilisent (l'appel échoue côté action si l'état a changé, l'erreur s'affiche dans le dialog) ; suppression de projet refusée par l'action, le message propose l'archivage (bouton vers `projects.archive`) ; actions éditer/supprimer rendues uniquement à l'auteur du commentaire ; validation inline du titre (champ requis, erreur sous le champ via `form.errors`) ; erreur de cycle du rattachement parent affichée au niveau du sélecteur de parent. Aucune condition métier dupliquée côté client au-delà de l'affichage conditionnel des contrôles (rôle, auteur) déjà porté par les props.

**Justification** : la règle de revue « toute requête/validation dans les Actions » vaut aussi pour le client : l'UI qui devance une règle en duplique une copie qui dérive. Afficher le refus de l'action = zéro duplication, message toujours vrai.

### ADR-6 : Livraison écran par écran, tokens et shell d'abord

**Décision** : ordre imposé par les tâches : (1) tokens + shell réglages, (2) écrans existants repris un par un (projets → détail issue → le reste), (3) board kanban, (4) membres. Chaque écran livré compile, passe les tests, et se compare au `screens/*.html` correspondant ouvert dans le navigateur.

**Justification** : recommandation du handoff ; un écran par commit garde chaque diff revuable contre sa maquette, et le changement de palette globale (ADR-1) atterrit en premier pour que chaque écran repris soit déjà dans les bonnes couleurs.

## Risks / Trade-offs

- [Divergence maquette ↔ application après livraison] → les maquettes sont datées et figées ; après cette change, `docs/maquettes/` devient une référence de conception, pas une spec vivante. Les évolutions UI postérieures se décident contre les specs, pas contre les maquettes.
- [DnD natif moins riche qu'une bibliothèque (pas de tactile fin, pas d'animations)] → assumé (ADR-3) ; alternative clavier « déplacer vers » maintenue ; réévaluation possible plus tard comme changement isolé.
- [Changement de palette globale casse des contrastes non repérés] → chaque écran repris est relu contre sa maquette ; la suite E2E vérifie les parcours ; aucun changement structurel de JSX dans la même passe que le changement de tokens (commit séparé).
- [Lecture seule member contournée côté client] → ce n'est pas une barrière : les actions backend refusent déjà (specs inchangées) ; l'UI ne fait que masquer des contrôles voués à l'échec.
- [Onglet « Tokens API » maquetté mais hors périmètre] → le shell réserve son emplacement avec un état « à venir » ; livré avec `mcp-access` sans refonte du shell.

## Migration Plan

Déploiement progressif sur l'application existante, sans migration de données ni de schéma :

1. Tokens oklch dans `resources/css/app.css` (commit isolé, `npm run build`).
2. Écrans existants repris un par un, chacun : JSX restylé contre sa maquette, `assertInertia` inchangés au vert, build ok.
3. Board : route + responder `ShowProjectBoard` + page, tests `assertInertia` + E2E drag & drop.
4. Membres : responder + page `Settings/Members`, tests (invitation owner-only, vue member lecture seule).
5. Suite complète au vert (`php artisan test`, suite Browser), `vendor/bin/pint --dirty --format agent`.

Rollback trivial par écran (git revert du commit de l'écran) ; aucune donnée touchée.

## Open Questions

- Mode sombre : les maquettes décrivent le thème clair uniquement — dériver les valeurs sombres des mêmes hue/chroma (approche `color-mix` du CSS existant), ou geler le dark sur la palette actuelle ? Non bloquant (le clair reprend `:root`).
- Filtres de l'index issues et état d'URL du board (colonne repliée, filtre assigné) : persistance en query string dès cette change ou plus tard ? Non bloquant.
- Écran d'acceptation d'invitation : l'action `AcceptInvitation` existe sans page dédiée ; la maquette `login.html` la maquette en invité. Créer `Pages/Invitations/Accept` (GuestLayout) dans cette change, ou garder le flux actuel ? Tranché en implémentation.
