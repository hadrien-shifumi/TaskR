> **Ordre d'implémentation conseillé : indépendante** — livrable à tout moment, en parallèle de `mcp-access`. Seul point de contact : l'onglet « Tokens API » des réglages, dont les actions dépendent de `mcp-access` (dépendance soft, non bloquante — livré avec elle, comme les outils time-tracking de `mcp-access`).

## Why

`docs/maquettes/` contient les maquettes HTML haute fidélité de Taskr (8 écrans, design system complet dans `assets/app.css`/`app.js`, intentions décrites dans `HANDOFF.md`). L'application est fonctionnelle mais porte encore le look shadcn par défaut et il manque des écrans maquettés (board kanban, membres des réglages). Les maquettes définissent le produit visible : palette oklch, hairlines, densité, présentation des règles métier. Cette change aligne l'UI dessus — sans toucher aux invariants backend, qui sont déjà tous spec'd.

## What Changes

- **Design tokens** : report des tokens oklch des maquettes dans le thème Tailwind v4 (`resources/css/app.css`), mappés sur les slots sémantiques shadcn existants (`--background`, `--card`, `--border`, `--primary` = accent bleu, `--destructive`) + nouveaux tokens sémantiques `--warning`/`--success`/`--info` pour les badges ; rayons 8/12 px, hairlines `--border` partout, mono tabulaire pour ULID et numériques.
- **Reprise écran par écran des écrans existants**, maquette comme référence visuelle : Auth (login/inscription/invitation), Home (dashboard workspace), Projects/Index, Projects/Health, Issues/Index, Issues/Show, Settings/Statuses, Settings/Templates. Les données de démo des maquettes sont remplacées par les vraies données Inertia ; `assets/app.js` vaut spec exécutable des interactions, pas du code à porter.
- **Nouvel écran : board kanban par projet** (`/projects/{project}/board`) — colonnes = statuts du workspace, cartes drag & drop (mouvement via la route existante `issues.move`), compteurs par colonne, état vide par colonne, cohérent avec la santé projet.
- **Nouvelle page réglages : membres** (`Settings/Members`) — liste des membres avec rôle, invitation par email, actions owner-only ; un `member` voit la section réglages en lecture seule.
- **Présentation UI des règles métier** (invariants déjà spec'd, aucun changement backend) : dialogue de suppression de statut avec migration préalable des issues, refus de suppression de projet proposant l'archivage, actions d'édition/suppression de commentaire réservées à l'auteur, validation inline du titre d'issue, erreur de cycle sur le rattachement parent.
- **Composants** : mapping un-pour-un des classes maquette vers les composants shadcn/ui existants ; tout nouveau primitif passe par le CLI shadcn (base UI, tokens) — jamais de CSS ad hoc ni de couleurs brutes.

Hors périmètre (volontairement) : `index.html` (launcher de présentation, documente le langage visuel — à lire, pas à implémenter), gabarits d'issues et tokens API fonctionnels (maquettés « à venir » ; les tokens API sont le périmètre de `mcp-access`), mode sombre (voir Open Questions), toute évolution métier.

## Capabilities

### New Capabilities

- `web-interface` : design tokens de l'application, inventaire des écrans et de leurs routes, board kanban, réglages multi-sections avec lecture seule member, présentation UI des règles métier, conventions de composants.

### Modified Capabilities

(néant — aucune exigence des specs backend ne change : `issue-tracking`, `status-management`, `project-management`, `workspace-management` continuent de porter tous les invariants ; cette change ne fait que les rendre visibles et conformes aux maquettes)

## Impact

- **Frontend** : `resources/css/app.css` (tokens) ; reprise de toutes les Pages existantes ; nouvelles `Pages/Projects/Board.tsx` et `Pages/Settings/Members.tsx` ; shell réglages partagé (tabs) ; aucun changement de layout racine.
- **Backend (limité)** : route `projects.board` + action/responder `ShowProjectBoard` (lecture groupée par statut) ; page membres + responder (lecture memberships, invitation via l'action existante) ; aucune migration, aucune modification d'action d'écriture existante.
- **Dépendances** : aucune a priori — le drag & drop du board utilise le DnD natif du navigateur (voir design.md) ; toute dépendance éventuelle serait soumise à validation avant installation.
- **Tests** : `assertInertia` sur les nouveaux responders (board, membres) ; suite Browser (E2E) pour les parcours maquettés (board drag & drop, migration de statut, lecture seule member) ; `npm run build` au vert.
