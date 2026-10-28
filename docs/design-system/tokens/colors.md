# Couleurs

## Architecture : des slots sémantiques, pas des couleurs

Taskr ne définit pas de palette « décorative » : les couleurs de la maquette (`docs/maquettes/assets/app.css`) sont **projetées sur les slots sémantiques shadcn**, de sorte que les primitives (`button`, `dialog`, `badge`…) fonctionnent sans connaître la palette. Ce mapping est documenté (ADR-1) dans le commentaire d'entête du bloc `:root` de `resources/css/app.css` :

| Token maquette | Slot(s) shadcn | Rôle |
|---|---|---|
| `--bg` `oklch(99% 0.002 240)` | `--background` | Fond de page |
| `--surface` `oklch(1 0 0)` | `--card`, `--popover` | Surfaces : cartes, menus, dialogs |
| `--fg` `oklch(0.18 0.012 250)` | `--foreground` (+ les `-foreground`) | Texte principal |
| `--muted` `oklch(0.54 0.012 250)` | `--muted-foreground` | Texte secondaire |
| `--border` `oklch(0.92 0.005 250)` | `--border`, `--input` | Hairlines et bordures d'inputs |
| `--accent` (bleu d'action) | `--primary`, `--ring` | Actions, focus |
| `--destructive` | `--destructive` | Danger |

Deux **extensions Taskr** au jeu de slots shadcn : `--warning`, `--success`, `--info` — portées par les badges sémantiques (statuts, priorités, santé projet). S'y ajoutent les slots standard `sidebar-*` (coquille de navigation) et `chart-1..5` (séries de graphiques).

> ⚠️ **Piège de vocabulaire.** `--accent` et `--muted` ne désignent pas la même chose côté maquette et côté shadcn :
> - la maquette `--accent` (bleu d'action) devient `--primary` côté app ;
> - la maquette `--muted` (gris de texte secondaire) devient `--muted-foreground`.
>
> Les slots shadcn `--accent` et `--muted` sont des **surfaces discrètes** (hovers, fonds de bandeau), dérivées par `color-mix(in oklch, …)` à partir de `--foreground`. Ne jamais « bleuir » `--accent`.

Les slots dérivés (`--secondary`, `--muted`, `--accent`, `--ring`, et les bordures du thème sombre) sont calculés par `color-mix(in oklch, …)` plutôt que copiés en dur : ils suivent automatiquement `--foreground` / `--primary`.

Les notations diffèrent légèrement de la maquette (`oklch(99% 0.002 240)` → `oklch(0.99 0.002 240)`) : Tailwind v4 note la lumière en décimal. Les valeurs sont identiques.

## Thème clair (`:root`)

| Token | Valeur verbatim | Rôle / usage |
|---|---|---|
| `--background` | `oklch(0.99 0.002 240)` | Fond de page (maquette `--bg`) |
| `--foreground` | `oklch(0.18 0.012 250)` | Texte principal (maquette `--fg`) |
| `--card` | `oklch(1 0 0)` | Fond des cartes et blocs (maquette `--surface`) |
| `--card-foreground` | `oklch(0.18 0.012 250)` | Texte sur carte |
| `--popover` | `oklch(1 0 0)` | Fond des éléments flottants : menus, dialogs, selects (maquette `--surface`) |
| `--popover-foreground` | `oklch(0.18 0.012 250)` | Texte sur popover |
| `--primary` | `oklch(0.55 0.18 255)` | Bleu d'action : boutons primaires, liens, éléments actifs (maquette `--accent`) |
| `--primary-foreground` | `oklch(0.99 0.002 240)` | Texte sur primaire |
| `--secondary` | `color-mix(in oklch, var(--foreground) 4%, transparent)` | Surface discrète : badges neutres, boutons secondaires |
| `--secondary-foreground` | `oklch(0.18 0.012 250)` | Texte sur secondaire |
| `--muted` | `color-mix(in oklch, var(--foreground) 4%, transparent)` | Surface « muted » : bandeaux, hovers, code inline |
| `--muted-foreground` | `oklch(0.54 0.012 250)` | Texte secondaire (maquette `--muted`) |
| `--accent` | `color-mix(in oklch, var(--foreground) 3%, var(--card))` | Surface de hover : items de menu, triggers ouverts — **pas** le bleu |
| `--accent-foreground` | `oklch(0.18 0.012 250)` | Texte sur accent |
| `--destructive` | `oklch(0.55 0.19 25)` | Danger : suppressions, erreurs de formulaire, retards, priorité urgent |
| `--border` | `oklch(0.92 0.005 250)` | Hairlines (maquette `--border`) |
| `--input` | `oklch(0.92 0.005 250)` | Bordures d'inputs, selects, textareas (maquette `--border`) |
| `--ring` | `color-mix(in oklch, var(--primary) 35%, transparent)` | Anneau de focus (`focus-visible:ring-3 ring-ring/50`) |
| `--warning` | `oklch(0.62 0.14 70)` | Priorité high, santé at-risk, bandeaux d'avertissement |
| `--success` | `oklch(0.58 0.13 150)` | Statut done, santé on track, progression |
| `--info` | `oklch(0.58 0.13 250)` | Statut in progress, priorité low, bandeaux info |
| `--sidebar` | `oklch(0.985 0.003 240)` | Fond de la sidebar |
| `--sidebar-foreground` | `oklch(0.18 0.012 250)` | Texte sidebar |
| `--sidebar-primary` | `oklch(0.55 0.18 255)` | Primaire dans la sidebar |
| `--sidebar-primary-foreground` | `oklch(0.99 0.002 240)` | Texte sur primaire sidebar |
| `--sidebar-accent` | `color-mix(in oklch, var(--foreground) 4%, transparent)` | Hover des items sidebar |
| `--sidebar-accent-foreground` | `oklch(0.18 0.012 250)` | Texte sur hover sidebar |
| `--sidebar-border` | `oklch(0.92 0.005 250)` | Hairline de la sidebar |
| `--sidebar-ring` | `color-mix(in oklch, var(--primary) 35%, transparent)` | Focus dans la sidebar |
| `--chart-1` | `oklch(0.58 0.13 250)` | Série 1 — bleu |
| `--chart-2` | `oklch(0.58 0.13 150)` | Série 2 — vert |
| `--chart-3` | `oklch(0.62 0.14 70)` | Série 3 — ambre |
| `--chart-4` | `oklch(0.55 0.19 25)` | Série 4 — rouge |
| `--chart-5` | `oklch(0.54 0.012 250)` | Série 5 — gris |

## Thème sombre (`.dark`)

Le thème sombre est dérivé de la même famille de teinte (hue 240–250), avec un `--primary` remonté en luminosité (`0.66` au lieu de `0.55`) et des bordures translucides par `color-mix` — voir le commentaire `app.css` (« design open question »).

| Token | Valeur verbatim | Rôle / usage |
|---|---|---|
| `--background` | `oklch(0.16 0.012 250)` | Fond de page |
| `--foreground` | `oklch(0.95 0.002 240)` | Texte principal |
| `--card` | `oklch(0.2 0.012 250)` | Fond des cartes et blocs |
| `--card-foreground` | `oklch(0.95 0.002 240)` | Texte sur carte |
| `--popover` | `oklch(0.2 0.012 250)` | Fond des éléments flottants |
| `--popover-foreground` | `oklch(0.95 0.002 240)` | Texte sur popover |
| `--primary` | `oklch(0.66 0.16 255)` | Bleu d'action (éclairci pour le dark) |
| `--primary-foreground` | `oklch(0.99 0.002 240)` | Texte sur primaire |
| `--secondary` | `color-mix(in oklch, var(--foreground) 8%, transparent)` | Surface discrète |
| `--secondary-foreground` | `oklch(0.95 0.002 240)` | Texte sur secondaire |
| `--muted` | `color-mix(in oklch, var(--foreground) 8%, transparent)` | Surface « muted » |
| `--muted-foreground` | `oklch(0.68 0.012 250)` | Texte secondaire |
| `--accent` | `color-mix(in oklch, var(--foreground) 6%, var(--card))` | Surface de hover |
| `--accent-foreground` | `oklch(0.95 0.002 240)` | Texte sur accent |
| `--destructive` | `oklch(0.63 0.19 25)` | Danger |
| `--border` | `color-mix(in oklch, var(--foreground) 12%, transparent)` | Hairlines (translucides, s'adaptent au fond) |
| `--input` | `color-mix(in oklch, var(--foreground) 16%, transparent)` | Bordures d'inputs |
| `--ring` | `color-mix(in oklch, var(--primary) 40%, transparent)` | Anneau de focus |
| `--warning` | `oklch(0.72 0.14 70)` | Priorité high, santé at-risk |
| `--success` | `oklch(0.68 0.13 150)` | Statut done, santé on track |
| `--info` | `oklch(0.7 0.13 250)` | Statut in progress, priorité low |
| `--sidebar` | `oklch(0.18 0.012 250)` | Fond de la sidebar |
| `--sidebar-foreground` | `oklch(0.95 0.002 240)` | Texte sidebar |
| `--sidebar-primary` | `oklch(0.66 0.16 255)` | Primaire sidebar |
| `--sidebar-primary-foreground` | `oklch(0.99 0.002 240)` | Texte sur primaire sidebar |
| `--sidebar-accent` | `color-mix(in oklch, var(--foreground) 8%, transparent)` | Hover sidebar |
| `--sidebar-accent-foreground` | `oklch(0.95 0.002 240)` | Texte sur hover sidebar |
| `--sidebar-border` | `color-mix(in oklch, var(--foreground) 12%, transparent)` | Hairline sidebar |
| `--sidebar-ring` | `color-mix(in oklch, var(--primary) 40%, transparent)` | Focus sidebar |
| `--chart-1` | `oklch(0.7 0.13 250)` | Série 1 — bleu |
| `--chart-2` | `oklch(0.68 0.13 150)` | Série 2 — vert |
| `--chart-3` | `oklch(0.72 0.14 70)` | Série 3 — ambre |
| `--chart-4` | `oklch(0.63 0.19 25)` | Série 4 — rouge |
| `--chart-5` | `oklch(0.68 0.012 250)` | Série 5 — gris |

## Convention « soft »

La maquette dérivait des fonds colorés discrets (`--*-soft`) par `color-mix(in oklch, … 10–12 %, transparent)`. Côté app, la même intention s'exprime de deux façons équivalentes — dans les deux cas **à partir d'un token, jamais d'une valeur brute** :

1. **Modificateur d'opacité Tailwind sur un token** — le pattern des badges (`resources/js/components/issue-badges.tsx`) : fond à 10 % + texte pleine couleur.

   ```tsx
   const tone =
       category === 'in_progress'
           ? 'bg-info/10 text-info'
           : category === 'done'
             ? 'bg-success/10 text-success'
             : 'bg-secondary/70 text-muted-foreground';
   ```

2. **Slots dérivés dans `app.css`** — `--secondary`, `--muted`, `--accent`, `--ring` et les bordures du thème sombre sont des `color-mix(in oklch, …)` déclarés une fois pour toutes.

Le mapping sémantique codifié des badges :

| Sémantique | Classes |
|---|---|
| Statut `in_progress` | `bg-info/10 text-info` |
| Statut `done` | `bg-success/10 text-success` |
| Statuts neutres (`backlog`, `cancelled`) | `bg-secondary/70 text-muted-foreground` |
| Priorité `urgent` | `bg-destructive/10 text-destructive` |
| Priorité `high` | `bg-warning/10 text-warning` |
| Priorité `low` | `bg-info/10 text-info` |
| Priorité `none` | `bg-secondary text-muted-foreground` |
| Santé on track / at risk / late | `success` / `warning` / `destructive` (`bg-*/10 text-*`) |

Comme sur la maquette, **la couleur vit dans la catégorie sémantique, jamais dans le nom libre** du statut ou du label.

## Règles d'usage

| Besoin | Slot | Notes |
|---|---|---|
| Actions principales, liens actifs | `primary` | `Button variant="default"`, `text-primary`, initiales d'avatar `bg-primary/20 text-primary` |
| Danger : suppression, erreur de validation, retard, urgent | `destructive` | `text-destructive` pour le texte, `bg-destructive/10` pour les fonds discrets |
| Texte secondaire, méta, placeholders | `muted-foreground` | Jamais d'opacité sur `foreground` pour faire du gris |
| Hairlines, séparations | `border` | Appliqué globalement par le `@layer base` (`* { border-border }`) |
| Bordures de champs de formulaire | `input` | `aria-invalid` bascule sur `destructive` |
| Focus visible | `ring` | Toujours via `focus-visible:ring-3 ring-ring/50` (déjà dans les primitives) |
| Statuts / priorités / santé | `info`, `success`, `warning`, `destructive` | Voir la table « soft » ci-dessus |
| Graphiques | `chart-1..5` | Réservés aux séries de données |
| Coquille de navigation | `sidebar-*` | Uniquement dans `app-layout.tsx` |

Interdits : hex brut (`#2563eb`), valeurs oklch en dur dans un composant, `text-gray-*` / `bg-blue-*` de la palette Tailwind brute. Si un besoin de couleur n'a pas de slot, c'est soit un mauvais slot choisi, soit un slot à ajouter dans `resources/css/app.css`.
