# Taskr — Design system

Le design system de Taskr tient en trois couches : les **tokens sémantiques Tailwind v4** (couleurs, typographie, rayons — définis une seule fois dans `resources/css/app.css`), les **primitives shadcn/ui** montées sur Base UI (style `base-nova`, voir `components.json`) et les **composants applicatifs** (`resources/js/components/`). Ce dossier documente les tokens et leur emploi ; il ne remplace ni le [brief de conception](../design-brief.md) ni le [handoff des maquettes](../maquettes/HANDOFF.md), qui restent les références amont (intentions produit, tokens verbatim de la maquette).

## Principes

- **Tokens sémantiques uniquement.** Jamais de hex brut, jamais de valeur oklch en dur dans un composant : on écrit `text-muted-foreground`, `bg-destructive/10`, `border-border`. Les valeurs réelles vivent exclusivement dans `resources/css/app.css`.
- **Deux thèmes, un seul jeu de classes.** Le thème clair (`:root`) et le thème sombre (`.dark`) partagent les mêmes slots sémantiques ; le variant `dark:` est scopé par `@custom-variant dark (&:is(.dark *))`. Un composant correctement tokénisé est thème-agnostique — s'il a besoin d'un ajustement dark, c'est le signe d'un mauvais slot.
- **Variantes via CVA.** Les primitives shadcn exposent leurs variantes (`variant`, `size`) via `class-variance-authority`. On consomme les variantes existantes plutôt que d'écraser les classes à la main.
- **Hairlines partout.** Toute séparation est une bordure 1px (`border-border`, appliqué globalement par le `@layer base`). Les ombres sont réservées aux éléments flottants (dialogs, menus) et aux micro-reliefs de contrôles (`shadow-xs`).
- **Une seule source de vérité.** Un token se modifie dans `resources/css/app.css` et nulle part ailleurs — pas de nouvelle feuille CSS.

## Galerie de composants

La galerie Storybook montre les **vraies** primitives et composants de l'app : les stories sont colocalisées avec leur composant (`resources/js/components/**/*.stories.tsx`) et importent le même code que l'application — la galerie ne peut pas dériver du code.

```bash
npm run storybook        # → http://localhost:6006
npm run build-storybook  # vérification du build (à passer avant de livrer)
```

La barre d'outils Storybook expose deux sélecteurs globaux :

- **Locale** : `en` / `fr` — bascule i18next, pour vérifier qu'un composant tient dans les deux langues ;
- **Theme** : `Light` / `Dark` — applique la classe `.dark` sur `<html>`, donc bascule tous les tokens du thème sombre définis dans `app.css`.

## Sommaire

### Tokens

- [Couleurs](tokens/colors.md) — architecture en slots sémantiques, mapping maquette → shadcn (ADR-1), tables complètes light/dark, convention des variantes « soft », règles d'usage.
- [Typographie](tokens/typography.md) — piles de polices (sans / heading / mono), échelle réellement utilisée, labels techniques, poids.
- [Rayons & élévation](tokens/radii-and-elevation.md) — échelle de rayons (8/12 px), pattern `min()` des petites tailles, hairlines, ombres.

### Composants & patterns

- [Composants](components.md) — inventaire des primitives et composants applicatifs.
- [Patterns UI](patterns.md) — compositions récurrentes (pagebar, formulaires en dialog, badges, listes).
- [Guide : ajouter un composant](guides/adding-a-component.md) — procédure shadcn CLI + story.

## Contexte amont

- [`docs/design-brief.md`](../design-brief.md) — produit, règles métier qui contraignent l'UI, inventaire des écrans.
- [`docs/maquettes/HANDOFF.md`](../maquettes/HANDOFF.md) — section 3 : tokens verbatim de la maquette et règles de design (hairlines, rayons 8/12 px, ombres réservées aux dialogs et menus).
- [`docs/maquettes/assets/app.css`](../maquettes/assets/app.css) — la source historique des valeurs oklch et des composants de maquette.
