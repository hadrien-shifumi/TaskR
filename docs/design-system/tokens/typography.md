# Typographie

## Piles de polices

Déclarées dans `resources/css/app.css` (`@theme` et `@theme inline`), conformes à la maquette : system sans pour le corps (SF Pro Text), SF Pro Display pour les titres, mono à chiffres tabulaires pour les ULID et les nombres.

| Token | Valeur verbatim | Usage |
|---|---|---|
| `--font-sans` | `-apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, sans-serif` | Police par défaut. Appliquée à `<html>` par le `@layer base` (`html { @apply font-sans }`) — aucune classe à ajouter. |
| `--font-heading` | `-apple-system, BlinkMacSystemFont, 'SF Pro Display', system-ui, sans-serif` | Titres / display (équivalent `--font-display` de la maquette). Porté par les primitives (`DialogTitle`, `CardTitle`), classe `font-heading`. |
| `--font-mono` | `ui-monospace, 'JetBrains Mono', 'SF Mono', Menlo, monospace` | ULID, nombres, labels techniques. Classe `font-mono`, **toujours** accompagnée de `tabular-nums`. |

## Échelle réellement utilisée

L'app n'a pas de reset typographique propriétaire : elle utilise l'échelle Tailwind plus un petit nombre de tailles arbitraires stabilisées par l'usage. Les tailles ci-dessous sont les seules à employer.

| Échelle | Usage | Référence dans le code |
|---|---|---|
| `text-[17px] leading-tight font-semibold tracking-tight` | Titre de pagebar (`h1` de page) | `app-layout.tsx`, prop `title` — reprend le `17px/600` de la maquette (`.pagebar-title`) |
| `text-base font-semibold` | Titre de section de page (`h2`) | Home, bloc « Santé des projets » |
| `text-sm font-semibold` | Titre de carte ou d'en-tête de bloc (`h3`) | Home, « Mes issues » ; `CardTitle` en `text-base` par défaut |
| `text-sm` | Corps par défaut : navigation, cellules de table, sous-titre de pagebar, textes de boutons | Partout — `body` de la maquette = `14px` |
| `text-[13px]` | Méta dense : nom du workspace dans le switcher, auteurs de commentaires et de time entries, selects de filtres, notes | `app-layout.tsx`, `comments-section.tsx`, `filter-bar.tsx` — le `--fs-meta` de la maquette |
| `text-xs` | Hints sous les chiffres, dates, compteurs discrets | `Home.tsx` |
| `text-[11px]` / `text-[12px]` | Micro-méta : rôle sous le nom d'utilisateur, compteurs kanban, badges de count | `app-layout.tsx`, kanban board |

## Labels techniques (uppercase mono)

Le pattern de section-label hérité de la maquette (`.sidebar-label`) : mono, capitales, letter-spacing large, `muted-foreground` :

```tsx
font-mono text-[12px] tracking-[0.08em] uppercase text-muted-foreground
```

Utilisé pour le label « Workspace » de la sidebar (`app-layout.tsx`) et les sections Knowledge (`Knowledge/Index.tsx`, `Versions.tsx`). Variante assouplie `tracking-[0.04em]` pour les libellés mono plus longs (résumé du temps dans l'issue detail).

À l'inverse des titres, ces labels **veulent** du letter-spacing positif — c'est le seul cas.

## Mono et chiffres tabulaires

`font-mono` est réservé aux **ULID, aux nombres et aux labels techniques** — jamais au corps de texte. Toujours avec `tabular-nums` pour l'alignement en colonne :

```tsx
{/* ULID, copiable */}
<span className="font-mono text-xs text-muted-foreground tabular-nums select-all">{issue.id}</span>

{/* date */}
<span className="font-mono text-xs tabular-nums">{formatDate(issue.dueDate)}</span>

{/* grand chiffre de stat */}
<p className="font-mono text-[28px] leading-tight font-semibold tracking-tight tabular-nums">{stat.value}</p>
```

Autres occurrences canoniques : `text-2xl font-semibold tabular-nums` (indicateurs Health), totaux `loggué / estimé` en `text-[13px]` ou `text-[18px]` mono, compteurs de colonne kanban en `text-[11px]`.

## Poids

- **`font-medium`** : emphase dans les flux de liste — titres d'issues dans les tables, noms de workspace/utilisateur, item de navigation actif (`aria-[current=page]:font-medium`).
- **`font-semibold`** : titres (pagebar, sections, cartes) et valeurs chiffrées fortes.
- **`font-bold`** : réservé aux micro-éléments (initiales d'avatar du switcher). Ne pas l'utiliser pour du texte courant.

## Règles

1. **Titres display = `font-heading`, via les primitives.** `DialogTitle` et `CardTitle` l'appliquent déjà ; ne pas l'ajouter à la main sur le `h1` de pagebar, qui reste en sans (`text-[17px]`) conformément à la maquette.
2. **`tracking-tight` sur les titres** (`text-[17px]`, `text-[28px]`, grands chiffres) — le `-0.02em` de la maquette sur `h1–h4`.
3. **Mono uniquement pour ULID / nombres / labels techniques**, avec `tabular-nums` systématique.
4. **Pas de taille arbitraire nouvelle** sans nécessité : réutiliser l'échelle ci-dessus ; si une taille manque, c'est qu'elle manque à tous — la stabiliser d'abord dans ce doc et dans les stories.
