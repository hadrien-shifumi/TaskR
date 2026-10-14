# Handoff — implémenter les maquettes Taskr

Ce document est destiné à un agent de code (opencode) travaillant dans le repo
`taskr`. Il décrit où sont les maquettes, ce qu'elles contiennent, et comment les
traduire en application réelle.

## 1. Où sont les maquettes

Les maquettes sont des HTML autonomes (statiques, avec interactions en JS
vanilla) produites dans Open Design. Deux options pour les rendre accessibles à
l'agent :

**Option A — copier dans le repo (recommandé) :**

```bash
mkdir -p docs/maquettes
cp -R "/Users/hadriendelphin/Library/Application Support/Open Design/namespaces/release-stable/data/projects/a5b9e503-e6da-449d-9f26-822e8033f6b7/assets" docs/maquettes/
cp -R "/Users/hadriendelphin/Library/Application Support/Open Design/namespaces/release-stable/data/projects/a5b9e503-e6da-449d-9f26-822e8033f6b7/screens" docs/maquettes/
cp    "/Users/hadriendelphin/Library/Application Support/Open Design/namespaces/release-stable/data/projects/a5b9e503-e6da-449d-9f26-822e8033f6b7/index.html" docs/maquettes/
```

**Option B — lecture directe** du même chemin absolu (lisible, mais hors du
workspace opencode ; moins pratique).

> `index.html` est un launcher de présentation (langage visuel) — pas un écran
> produit. Les écrans réels sont dans `screens/`, les styles et scripts partagés
> dans `assets/app.css` et `assets/app.js`.

## 2. Inventaire des écrans → routes suggérées

| Maquette | Écran | Route suggérée |
|---|---|---|
| `screens/login.html` | Connexion / inscription / invitation | `/login`, `/signup`, `/invite/…` |
| `screens/home.html` | Dashboard workspace | `/` |
| `screens/projects.html` | Liste projets, santé, archivage | `/projects` |
| `screens/project-health.html` | Santé projet (répartition, retards, heures) | `/projects/:id/health` |
| `screens/project-board.html` | Kanban par projet (drag & drop) | `/projects/:id/board` |
| `screens/issues.html` | Liste issues cross-projet, filtres | `/issues` |
| `screens/issue-detail.html` | Détail issue (statut, temps, commentaires, parent) | `/issues/:id` |
| `screens/settings.html` | Statuts (drag), membres, gabarits, tokens API | `/settings/*` |

## 3. Design tokens (verbatim, à reporter dans Tailwind v4 / CSS)

```css
--bg:      oklch(99% 0.002 240);
--surface: oklch(100% 0 0);
--fg:      oklch(18% 0.012 250);
--muted:   oklch(54% 0.012 250);
--border:  oklch(92% 0.005 250);
--accent:  oklch(58% 0.18 255);

--destructive: oklch(55% 0.19 25);
--warning:     oklch(62% 0.14 70);
--success:     oklch(58% 0.13 150);
--info:        oklch(58% 0.13 250);
```

Dérivés via `color-mix(in oklch, …)` — voir `assets/app.css` bloc `:root`.
Typographie : display/body = system sans (`-apple-system, …`), mono tabulaire
pour ULID et numériques. Rayons : 8px / 12px. Hairlines (`--border`) partout,
ombres réservées aux dialogs et menus.

## 4. Mapping composants CSS → ShadCN/ui

Les classes des maquettes sont conçues pour se mapper un-à-un :

- `.btn` / `.btn-primary` / `.btn-ghost` / `.btn-destructive` → `button` (variants)
- `.card` → `card`
- `.badge` / `.badge-*` → `badge`
- dialog (project delete, status delete…) → `dialog`
- `.data-table` → `table`
- `.tabs` → `tabs`
- `.field` → `label` + `input`
- kanban drag & drop (`assets/app.js`) → `dnd` (ShadCN) ou équivalent

## 5. Règles métier jouées par les maquettes (à implémenter telles quelles)

- Statuts libres **au niveau workspace** (Backlog, À affiner, In Progress, En
  revue, Done, Cancelled), réordonnables par drag dans Settings.
- Bascule **owner / member** : le member voit les Settings en lecture seule.
- Édition/suppression d'un commentaire ou d'une time entry : **auteur uniquement**.
- Suppression d'un statut : **refusée** tant que des issues l'utilisent →
  migration des issues vers un autre statut d'abord (dialog de confirmation).
- Suppression d'un projet : **refusée** → proposer l'archivage.
- Rattachement parent d'issue : **erreur de cycle** si ça crée une boucle.
- Création d'issue : **titre requis** (validation inline).
- Gabarits d'issues et tokens API : maquettés dans Settings, marqués « à venir »
  (non bloquants pour la v1).

## 6. Prompt à donner à opencode

À coller tel quel dans une session opencode ouverte à la racine du repo
(après avoir copié les maquettes en `docs/maquettes/`) :

> Dans `docs/maquettes/` tu as des maquettes HTML haute fidélité de Taskr :
> `assets/app.css` (design tokens + composants), `assets/app.js` (interactions),
> et un écran par fichier dans `screens/` (voir le tableau du handoff pour la
> correspondance écran → route). Implémente ces écrans dans l'app en suivant le
> design system existant du repo ; si des tokens y sont absents, reporte les
> valeurs oklch de `assets/app.css` verbatim. Les maquettes sont statiques :
> remplace les données de démo par les vraies données via l'API/DB, et implémente
> les règles métier listées dans `docs/maquettes/HANDOFF.md` section 5. Commence
> par la liste des projets puis le détail d'issue, et livre écran par écran.

## 7. Conseils de workflow

1. Livrer écran par écran (chaque fichier `screens/*.html` est indépendant et
   consultable dans le navigateur comme référence visuelle).
2. `assets/app.js` montre les interactions attendues (drag & drop kanban,
   filtres, dialogs, validation) — c'est une spec exécutable, pas du code à
   porter tel quel.
3. Données de démo : workspace « Studio Nova », projet Stripe avec 18 issues
   (12 done, 3 in progress, 2 backlog, 1 cancelled) — les compteurs sont
   cohérents entre écrans, utile pour vérifier ses requêtes.
4. `index.html` (launcher) documente le langage visuel : à lire en premier pour
   comprendre les intentions, à ne pas implémenter.
