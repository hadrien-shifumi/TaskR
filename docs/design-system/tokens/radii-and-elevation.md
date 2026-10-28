# Rayons & élévation

## Échelle de rayons

Redéfinie dans `resources/css/app.css` (`@theme inline`) pour coller à la règle maquette : **12 px rayon maître** (cartes, dialogs, conteneurs), **8 px pour les petits éléments** (items de menu, petites pièces). Commentaire verbatim : `/* Maquette radii: 12px master, 8px for small elements (buttons, inputs). */`

| Token | Valeur verbatim | Pixels | Usage |
|---|---|---|---|
| `--radius-sm` | `0.5rem` | 8 px | Disponible pour les très petites pièces |
| `--radius-md` | `0.5rem` | 8 px | Items de menu, items de select, blocs internes (`rounded-md`) |
| `--radius-lg` | `0.75rem` | 12 px | **Rayon maître des contrôles** : `Button`, `Input`, `Textarea`, `Select`, `rounded-lg` |
| `--radius-xl` | `0.75rem` | 12 px | Conteneurs : `Card`, `DialogContent`, blocs `rounded-xl` (kanban, listes) |
| `--radius-2xl` | `1rem` | 16 px | Réservé |
| `--radius-3xl` | `1.25rem` | 20 px | Réservé |
| `--radius-4xl` | `1.5rem` | 24 px | Réservé |

Note : `sm` = `md` = 8 px et `lg` = `xl` = 12 px — l'échelle est volontairement aplatie sur les deux valeurs de la maquette ; les crans 2xl–4xl existent pour compatibilité Tailwind mais ne doivent pas apparaître dans l'UI.

## Le pattern `min()` pour les petites tailles

Les tailles compactes plafonnent le rayon pour éviter l'effet « pill » sur des hauteurs de 24/28 px. Le clamp est exprimé **relativement au token**, jamais en pixels bruts :

```tsx
// resources/js/components/ui/button.tsx
xs:      "h-6 … rounded-[min(var(--radius-md),10px)] …"
sm:      "h-7 … rounded-[min(var(--radius-md),12px)] …"
icon-xs: "size-6 rounded-[min(var(--radius-md),10px)] …"
icon-sm: "size-7 rounded-[min(var(--radius-md),12px)] …"
```

`Select` fait de même pour sa taille `sm` (`data-[size=sm]:rounded-[min(var(--radius-md),10px)]`). À réutiliser telle quelle si vous introduisez une nouvelle taille compacte.

## Hairlines

La règle maquette est « hairlines (`--border`) partout ». Côté app :

- le `@layer base` applique `border-border` à `*` : toute bordure 1px non qualifiée est déjà une hairline tokenisée — écrire `border`, `border-b`, `border-r` suffit (pagebar, sidebar, cellules de table, sections empilées) ;
- les primitives base-nova expriment la hairline par `ring-1 ring-foreground/10` (`Card`, `DialogContent`, contenus de menus) : équivalent visuel, à laisser tel quel ;
- les bordures colorées sont l'exception sémantique, pas la règle : focus (`focus-visible:border-ring`), erreur (`aria-invalid:border-destructive`), bannières (`warning`/`destructive`/`info` à ~30–35 % dans `border-color`).

Pas de bordures 2px, pas de doubles bordures : deux blocs adjacents se séparent par une seule hairline (voir les listes de statuts, time entries).

## Élévation (ombres)

La règle maquette : « ombres réservées aux dialogs et menus ». Déclinaison app :

| Élément | Traitement | Référence |
|---|---|---|
| Contrôles posés (switcher de workspace, segmented control auth, trigger outline) | `shadow-xs` au maximum — un micro-relief, pas une élévation | `app-layout.tsx`, `Pages/Auth/*` |
| Menus (dropdown, select) | `shadow-md` / `shadow-lg` + `ring-1 ring-foreground/10` — la seule vraie élévation avec les dialogs | `ui/dropdown-menu.tsx`, `ui/select.tsx` |
| Dialogs | `rounded-xl` + `ring-1 ring-foreground/10` ; base-nova n'empile pas d'ombre lourde, le backdrop fait la séparation | `ui/dialog.tsx` |
| Cartes statiques | **Aucune ombre** : la séparation passe par la hairline et le contraste `bg-card` / `bg-background` | `ui/card.tsx` |

Règle pratique : si un élément ne flotte pas au-dessus de la page, il n'a pas d'ombre. Toute valeur de `shadow-*` supérieure à `shadow-xs` hors menus/dialogs est un écart au design system.

## Focus

Le focus n'est ni un rayon ni une ombre mais il relie les deux échelles : les primitives utilisent `focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50` (anneau `--ring`, dérivé du `--primary` à ~35–40 %). Ne jamais réintroduire d'`outline` personnalisé.
