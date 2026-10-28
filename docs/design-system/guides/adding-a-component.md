# Guide contributeur — ajouter un composant

Parcours complet pour introduire une nouvelle brique UI dans Taskr : ajout de la primitive, règles de style, story Storybook, documentation et vérification. S'applique aux primitives (`resources/js/components/ui/`) comme aux composants applicatifs (`resources/js/components/`).

---

## 1. Ajouter une primitive (shadcn)

Une primitive manquante s'ajoute avec le CLI shadcn — jamais copiée à la main depuis la doc :

```bash
npx shadcn add <component>
```

La configuration (`components.json`) garantit un résultat conforme d'office :

- **style `base-nova`**, composants construits sur **Base UI** (`@base-ui/react`) — pas Radix ;
- **Tailwind v4** sans fichier de config, tokens injectés dans **`resources/css/app.css`** ;
- aliases : `@/components`, `@/components/ui`, `@/lib/utils`, `@/lib`, `@/hooks` ;
- icônes **lucide-react**.

Le CLI produit un composant piloté par les tokens : conservez-le tel quel (indentation **2 espaces** sous `components/ui/`, style shadcn), aux ajustements de tokens près.

> Composant applicatif plutôt que primitive ? Écrivez-le dans `resources/js/components/` (indentation **4 espaces**) en composant les primitives existantes — voir [components.md](../components.md). S'il ne sert qu'à une page, il peut vivre dans `Pages/<Domain>/components/` tant qu'aucune autre page ne le partage.

## 2. Règles de style (non négociables)

1. **Tokens sémantiques uniquement.** `bg-primary`, `text-muted-foreground`, `bg-info/10`, `border-destructive/35`… Jamais de hex arbitraire, jamais de valeur de couleur brute dans une classe.
2. **Pas de nouveau fichier CSS.** Tout passe par les utilitaires Tailwind v4 ; `resources/css/app.css` reste le seul endroit où les tokens sont définis.
3. **Variantes via CVA** (`class-variance-authority`) quand le composant a plusieurs variantes ou tailles — avec `defaultVariants` explicites et les types dérivés (`VariantProps<typeof xVariants>`).
4. **`cn()` depuis `@/lib/utils`** (ou le paquet `cn`) pour toute classe conditionnelle. Pas de concaténation de template strings sur des classes en conflit.
5. **Composants Base UI, pas Radix.** Les primitives enveloppent `@base-ui/react/*` ; suivez les props de la fiche shadcn existante plutôt que la doc Radix.
6. **États standards attendus** sur un champ ou un contrôle : `focus-visible` (anneau `ring-ring/50`), `disabled` (opacité 50 %), `aria-invalid` (bordure destructive), et `aria-expanded` sur les triggers de menus/popovers.
7. **`data-slot` sur chaque élément racine** des sous-composants — c'est ce qui permet les sélecteurs `data-[slot=…]` et les tests.

## 3. Ajouter une story Storybook

Storybook tourne sur `npm run storybook` (build : `npm run build-storybook`). Les stories sont **colocalisées** avec leur composant, en `*.stories.tsx` à côté du fichier du composant — le glob de `.storybook/main.ts` les ramasse toutes (`resources/js/**/*.stories.@(ts|tsx)`).

Conventions (documentées en tête de [`button.stories.tsx`](../../../resources/js/components/ui/button.stories.tsx), la story de référence) :

- **CSF3** : `const meta = { … } satisfies Meta<typeof Component>` + `type Story = StoryObj<typeof meta>` ; `tags: ['autodocs']`.
- **Titres imposés** : `UI/<Name>` pour les primitives de `components/ui/`, `Components/<Name>` pour les composants de `components/` (ex. `UI/Button`, `Components/IssueBadges`). L'ordre de tri global est `UI` puis `Components`.
- **Contrôles** : `argTypes` exposant un `control: 'select'` (ou équivalent) pour **chaque** variante et taille, avec `description` — la story Playground doit pouvoir dérouler toute la matrice. Ajoutez des stories de galerie (`Variants`, `Sizes`, états) en plus du Playground.
- **Indentation** : 2 espaces sous `components/ui/`, 4 espaces sous `components/`.
- **Tokens seulement** dans les styles des stories (`bg-primary`, `text-muted-foreground`…), jamais de hex — le décorateur bascule le thème `.dark` via la toolbar, vos stories doivent tenir en clair comme en sombre.

Le preview `.storybook/preview.tsx` fournit déjà le contexte applicatif : import des tokens (`resources/css/app.css`), stub global `route()` (renvoie `#<name>`), mock de `@inertiajs/react` (`.storybook/mocks/`), et deux toolbars — **Locale** (fr/en via i18n) et **Theme** (light/dark). Vos stories doivent donc rester renderables sans backend : préférez les props brutes (tableaux d'options, chaînes) aux appels réseau, et gardez à l'esprit que `route()` ne renvoie que des ancres.

## 4. Documenter le composant

Toute nouvelle brique — primitive **ou** composant applicatif — doit avoir sa fiche dans [components.md](../components.md) :

- **Primitives** : sous-section dans « Primitives UI (shadcn / Base UI) » — exports, variantes/tailles, états notables, règles d'usage, lien vers la story `UI/<Name>`.
- **Composants applicatifs** : sous-section dans « Composants applicatifs » — but, props, tableaux de tons le cas échéant, marqueur **« couplé à Inertia »** s'il importe `@inertiajs/react` (sinon « pur »), lien vers la story `Components/<Name>`.
- Si le composant incarne un pattern récurrent (filtre, état vide, formulaire en dialog…), référencez-le depuis [patterns.md](../patterns.md).

## 5. Libellés user-facing : i18n

Tout texte visible passe par i18n (react-i18next), jamais en dur dans le composant :

- **`resources/js/i18n/en.ts` est la source de vérité** de la structure des clés ;
- **`fr.ts` est typé `typeof en`** : si une traduction manque, **le build TypeScript casse** — impossible d'oublier une langue en silence ;
- clés regroupées par domaine (`issues.*`, `projectsPage.*`, `common.*`…) ; ajoutez la clé au pluriel des deux fichiers dans la même PR ;
- dans Storybook, la toolbar Locale change la langue en live : les composants consomment `useTranslation()` et restent donc vérifiables dans les deux langues.

## 6. Vérification

Avant de pousser, les trois contrôles doivent passer sans nouvelle erreur :

```bash
npm run build              # build applicatif (Vite + TypeScript)
npm run build-storybook    # build de la galerie de stories
npx tsc --noEmit           # aucune nouvelle erreur de type
```

- `npm run build` casse notamment si `fr.ts` n'est plus aligné sur `en.ts` (typage `typeof en`) ou si les interfaces de props ne matchent plus les payloads Inertia.
- `npm run build-storybook` échoue si une story viole les titres/CSF ou référence un export inexistant.
- `npx tsc --noEmit` doit être **sans nouvelle erreur** : comparez au niveau de branche, pas au zéro absolu si des erreurs préexistaient.

Côté backend, les tests `assertInertia` couvrent les payloads : si votre composant consomme une prop nouvelle, la mise à jour du responder et de l'interface de page se fait dans la même tâche (voir le skill `taskr-frontend`).
