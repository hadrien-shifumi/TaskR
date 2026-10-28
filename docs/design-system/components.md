# Catalogue des composants

Inventaire de référence des briques UI de Taskr : primitives shadcn/Base UI d'une part, composants applicatifs d'autre part. Chaque fiche liste les exports, les variantes et les états notables, et renvoie à la story Storybook correspondante (`npm run storybook`). Les tokens de couleur (`primary`, `muted`, `destructive`, `info`, `success`, `warning`…) sont détaillés dans le [README](./README.md) du design system ; les briques présentées ici ne se combinent qu'entre elles.

## Règles d'utilisation (non négociables)

- **Importer depuis `@/components/ui/*`.** Les primitives sont la seule source de boutons, champs, dialogs, etc. On ne réécrit jamais une primitive à la main.
- **Besoin d'une nouvelle primitive ?** On l'ajoute avec le CLI shadcn (`npx shadcn add <component>`) pour qu'elle reste pilotée par les tokens et basée sur Base UI — voir [le guide contributeur](./guides/adding-a-component.md).
- **Classes conditionnelles : `cn()`** depuis `@/lib/utils` (ou le paquet `cn`). Jamais de concaténation de template strings pour les classes sujettes à conflit.
- **Tokens uniquement.** `text-muted-foreground`, `bg-muted/50`, `text-destructive`… Aucun hex brut, aucun nouveau fichier CSS.

---

## Primitives UI (shadcn / Base UI)

Toutes les primitives vivent dans `resources/js/components/ui/` et gardent leur style d'indentation 2 espaces d'origine shadcn. Elles sont construites sur `@base-ui/react` (pas Radix) et stylées exclusivement en tokens Tailwind v4.

### Button — story `UI/Button`

- **Exports :** `Button`, `buttonVariants`.
- **Variantes :** `default` (pleine, `bg-primary`), `outline` (bordure + fond transparent), `secondary` (fond `bg-secondary`), `ghost` (transparent, fond au survol), `destructive` (`bg-destructive/10 text-destructive` — teinte douce, pas un bloc rouge plein), `link`.
- **Tailles :** `default` (h-8), `xs` (h-6), `sm` (h-7), `lg` (h-9), plus les carrées `icon`, `icon-xs`, `icon-sm`, `icon-lg`. Les tailles `icon*` exigent un `aria-label` quand le bouton ne contient qu'une icône.
- **États :** `focus-visible` (anneau `ring-ring/50`), `disabled` (opacité 50 %, pointeur désactivé), `aria-invalid` (bordure destructive), `aria-expanded` (fond `bg-muted` sur outline/secondary/ghost — c'est l'état des triggers de menus), légère translation au clic (`active:translate-y-px`).
- **Usage :** `variant="outline|ghost|secondary"` par défaut dans l'app ; `default` réservé à l'action principale de la page/dialog ; `destructive` aux confirmations de suppression. Les icônes (lucide-react) sont dimensionnées automatiquement (`size-4`).

### Badge — story `UI/Badge`

- **Exports :** `Badge`, `badgeVariants`.
- **Variantes :** `default`, `secondary`, `destructive` (teinte douce `bg-destructive/10`), `outline`, `ghost`, `link`. Pas de tailles : hauteur fixe h-5, `text-xs`.
- **États :** `focus-visible` (utile quand rendue comme lien via `render`), `aria-invalid`. Les styles `[a]:hover` s'activent quand le `render` produit une ancre.
- **Usage :** les badges sémantiques métier (`StatusBadge`, `PriorityBadge`, `HealthBadge`) l'enveloppent toujours en `variant="secondary"` + classes de ton arrondies (`rounded-full`). Voir [Composants applicatifs](#composants-applicatifs).

### Card — story `UI/Card`

- **Exports :** `Card`, `CardHeader`, `CardTitle`, `CardDescription`, `CardAction`, `CardContent`, `CardFooter`.
- **Props :** `size?: "default" | "sm"` — pilote l'espacement interne via la variable CSS `--card-spacing` (4 / 3).
- **États notables :** aucun état interactif ; structure en `data-slot` + `group/card`. `CardFooter` est full-bleed (`border-t bg-muted/50`), `CardAction` se cale en haut à droite de l'en-tête.
- **Usage :** blocs d'indicateurs (pages Health, dashboards). `CardTitle` utilise la police heading ; `CardDescription` est en `text-muted-foreground`.

### Dialog — story `UI/Dialog`

- **Exports :** `Dialog`, `DialogTrigger`, `DialogPortal`, `DialogOverlay`, `DialogContent`, `DialogHeader`, `DialogTitle`, `DialogDescription`, `DialogFooter`, `DialogClose`.
- **Structure imposée :** `DialogContent` contient `DialogHeader` (`DialogTitle` + `DialogDescription`), puis le corps, puis `DialogFooter` pour les actions.
- **Comportements :** `disablePointerDismissal` vaut `true` par défaut — un clic sur le backdrop ne ferme **pas** le dialog (protection de la saisie en cours) ; seule la croix ou Échap ferme. `DialogContent` accepte `showCloseButton` (croix en haut à droite, `variant="ghost" size="icon-sm"`), `DialogFooter` accepte aussi `showCloseButton` (bouton « Close » outline).
- **États :** overlay `bg-black/10` + blur, animations d'ouverture/fermeture ; largeur `sm:max-w-sm` par défaut, extensible via `className` (voir `contentClassName` de FormDialog).
- **Usage :** ne jamais composer un dialog à la main hors de cette structure ; pour les formulaires Inertia, passer par [FormDialog](#formdialog--story-componentsformdialog).

### DropdownMenu — story `UI/DropdownMenu`

- **Exports :** `DropdownMenu`, `DropdownMenuTrigger`, `DropdownMenuPortal`, `DropdownMenuContent`, `DropdownMenuGroup`, `DropdownMenuLabel`, `DropdownMenuItem`, `DropdownMenuCheckboxItem`, `DropdownMenuRadioGroup`, `DropdownMenuRadioItem`, `DropdownMenuSeparator`, `DropdownMenuShortcut`, `DropdownMenuSub`, `DropdownMenuSubTrigger`, `DropdownMenuSubContent`.
- **Props notables :** `DropdownMenuContent` : `align` (défaut `start`), `alignOffset`, `side` (défaut `bottom`), `sideOffset`. `DropdownMenuItem` : `variant?: "default" | "destructive"` (les entrées destructives passent en `text-destructive` avec survol `bg-destructive/10`) et `inset` pour l'alignement sous une icône.
- **États :** focus `bg-accent`, items désactivés opacité 50 %, `data-popup-open` sur les sous-menus. Le trigger est souvent un `Button variant="ghost" size="icon-sm"` via la prop `render`.
- **Usage :** menus d'actions par ligne (voir `ProjectMenu`), menus de compte/workspace dans [AppLayout](#pagebar--coquille-applicative).

### Input — story `UI/Input`

- **Exports :** `Input`.
- **Variantes :** aucune — un seul style (h-8, `border-input`, fond transparent).
- **États :** `focus-visible` (anneau ring), `disabled` (fond `bg-input/50`), `aria-invalid` (bordure destructive + anneau), `placeholder:text-muted-foreground`. Les `type="file"` ont leur rendu dédié.
- **Usage :** toujours relié à un `<Label htmlFor>` ; l'erreur de champ s'affiche **sous** l'input (voir [le pattern](./patterns.md#formulaires-inertia)) avec `aria-invalid` sur l'input.

### Textarea — story `UI/Textarea`

- **Exports :** `Textarea`.
- **Variantes :** aucune — `field-sizing-content` : la hauteur suit le contenu (`min-h-16`).
- **États :** identiques à Input (focus-visible, disabled, aria-invalid).
- **Usage :** descriptions courtes en formulaires ; pour le contenu riche Markdown, utiliser [MarkdownEditor](#markdowneditor--story-uimarkdowneditor).

### Select — story `UI/Select`

- **Exports :** `Select` (racine Base UI ré-exportée), `SelectTrigger`, `SelectValue`, `SelectContent`, `SelectGroup`, `SelectItem`, `SelectLabel`, `SelectSeparator`, `SelectScrollUpButton`, `SelectScrollDownButton`.
- **Tailles :** `SelectTrigger` accepte `size?: "sm" | "default"` (h-7 / h-8).
- **États :** `data-placeholder:text-muted-foreground`, `aria-invalid` (bordure destructive), `data-[size]` pilote la hauteur. Items : focus `bg-accent` + coche d'indication (`CheckIcon`) à droite.
- **Usage :** la valeur affichée se contrôle via les enfants de `SelectValue` (pattern filtre : afficher le label trouvé, voir [FilterSelect](#filterselect--story-componentsfilterselect)). Toujours garder un `onValueChange` défensif (`(v) => v && …`) car Base UI peut émettre une valeur vide.

### Checkbox — story `UI/Checkbox`

- **Exports :** `Checkbox`.
- **Variantes :** aucune — `size-4`, coins 4px.
- **États :** `data-checked` (fond `bg-primary`, texte primaire), `focus-visible` (anneau), `disabled`, `aria-invalid`. Une zone cliquable élargie (`after:-inset-x-3 after:-inset-y-2`) facilite le tap mobile.
- **Usage :** cases à cocher simples de formulaire ; pour la sélection multiple « chips » (labels, assignés), préférer les patterns `LabelPicker` / toggles `aria-pressed`.

### Label — story `UI/Label`

- **Exports :** `Label`.
- **Variantes :** aucune — `text-sm font-medium`.
- **États :** s'estompe automatiquement quand le champ lié est désactivé (`peer-disabled`, `group-data-[disabled]`).
- **Usage :** `<Label htmlFor="id-du-champ">` au-dessus de chaque champ de formulaire, dans une grille `grid gap-2`.

### Table — story `UI/Table`

- **Exports :** `Table`, `TableHeader`, `TableBody`, `TableFooter`, `TableHead`, `TableRow`, `TableCell`, `TableCaption`.
- **Structure :** `Table` enveloppe le `<table>` dans un conteneur `overflow-x-auto` (scroll horizontal mobile).
- **États :** `TableRow` a un survol `hover:bg-muted/50` et suit `aria-expanded` ; `TableFooter` est `bg-muted/50` avec bordure haute.
- **Usage :** structure standard des listes — voir [le pattern « tableaux de liste »](./patterns.md#tableaux-de-liste). Largeurs de colonnes en classes utilitaires (`w-[30%]`, `w-10` pour la colonne d'actions).

### MarkdownEditor — story `UI/MarkdownEditor`

- **Exports :** `MarkdownEditor`, `MarkdownView`.
- **Props :** `value` (chaîne Markdown brute, contrôlée), `onChange` (sa présence rend l'éditeur éditable), `className`. `MarkdownView` : `value`, `className` (rendu lecture seule).
- **Contenu :** Tiptap + tiptap-markdown — titres, gras/italique/souligné/barré/code, listes (puces, numérotées, tâches), citations, blocs de code, tableaux, liens, séparateur. Toolbar en boutons `ghost icon-sm` avec `aria-pressed` sur les marques actives ; placeholder « Write in markdown… ».
- **États :** le contour du composant passe en anneau de focus via `focus-within` (l'éditeur n'est pas un input natif) ; undo/redo désactivés quand indisponibles.
- **Usage :** description d'issue, corps de gabarit, commentaires — la valeur transportée par Inertia reste une **chaîne Markdown**, pas du HTML.

---

## Composants applicatifs

Les composants applicatifs vivent dans `resources/js/components/` (indentation 4 espaces). Deux familles :

- **Pure components** : aucune dépendance à Inertia — testables et storyables tels quels.
- **Composants couplés à Inertia** : importent de `@inertiajs/react` (`usePage`, `useForm`, `router`, `Link`). Ils sont notés ci-dessous et consomment les props de page ou les routes ziggy.

> Dans Storybook, `@inertiajs/react` est mocké (`.storybook/mocks/`) et `route()` est un stub global : les composants couplés restent donc renderables, mais leur logique réseau n'y est pas exercée.

### IssueBadges — story `Components/IssueBadges`

Fichier `issue-badges.tsx`. Badges sémantiques partagés par tous les écrans d'issues. **Pur.**

- **Exports :** `StatusBadge`, `PriorityBadge`, `HealthBadge`, `Avatar`, `AvatarStack`, `initials(name)`.
- **Règle :** couleurs **uniquement** via tokens (`info` / `success` / `warning` / `destructive` / `secondary`), toujours en teinte douce (`bg-x/10`) sur une `Badge variant="secondary" rounded-full`, avec point `size-1.5 bg-current` sauf mention contraire.

**StatusBadge** (`name`, `category`) — le ton vient de la **catégorie** du statut, pas de son nom (libre) :

| Catégorie | Ton |
|---|---|
| `in_progress` | `bg-info/10 text-info` |
| `done` | `bg-success/10 text-success` |
| autres (`backlog`, `cancelled`) | `bg-secondary/70 text-muted-foreground` |

**PriorityBadge** (`priority`) — labels traduits via `t('priority.*')` :

| Priorité | Ton |
|---|---|
| `urgent` | `bg-destructive/10 text-destructive` |
| `high` | `bg-warning/10 text-warning` |
| `low` | `bg-info/10 text-info` |
| `none` | `bg-secondary text-muted-foreground`, sans point |

**HealthBadge** (`status: string | null`) — labels `t('health.*')` :

| Projection | Ton |
|---|---|
| `ok` | `bg-success/10 text-success` |
| `at-risk` | `bg-warning/10 text-warning` |
| `late` | `bg-destructive/10 text-destructive` |
| `null` (pas de projection) | `bg-secondary text-muted-foreground`, sans point |

**Avatar / AvatarStack** — `Avatar` rend les initiales (helper `initials`) dans une pastille `bg-primary/20 text-primary border-primary/25`. `AvatarStack({ people })` empile les avatars avec chevauchement (`-ml-1.75`) et ne rend **rien** si la liste est vide (pattern cellule nullable). Chaque personne : `{ id, name }`.

### Banner — story `Components/Banner`

Bandeau d'information (fonds doux, bordure fine colorée, icône de tête). **Pur.**

- **Props :** `variant?: 'info' | 'warning' | 'danger'` (défaut `info`), `icon?: LucideIcon | null` (défaut = icône de la variante ; `null` pour aucune), `role?: 'status' | 'alert'` (défaut `status`), `children`.
- **Tons :**

| Variante | Bordure / fond | Icône (défaut) |
|---|---|---|
| `info` | `border-info/30 bg-info/10` | `Info`, `text-info` |
| `warning` | `border-warning/35 bg-warning/10` | `TriangleAlert`, `text-warning` |
| `danger` | `border-destructive/35 bg-destructive/10 text-destructive` | `TriangleAlert` |

- **Usage :** messages d'information persistants (lecture seule member, refus de suppression…). Annoncé aux lecteurs d'écran via `role`. Pour le message de succès éphémère, voir [FlashBanner](#flashbanner--story-componentsflashbanner).

### Progress — story `Components/Progress`

Barre de progression fine (piste h-1.5 `bg-secondary`, remplissage arrondi). **Pur.**

- **Props :** `value` (pourcentage, borné 0–100), `color?: ProgressColor` (défaut `accent`).
- **Tons de remplissage :**

| Color | Classe |
|---|---|
| `accent` | `bg-primary` |
| `success` | `bg-success` |
| `info` | `bg-info` |
| `destructive` | `bg-destructive` |
| `muted` | `bg-muted-foreground` |
| `muted-soft` | `bg-muted-foreground/50` |

- **Usage :** avancement % des projets. La couleur est un slot sémantique, jamais une valeur brute.

### FormDialog — story `Components/FormDialog`

Coquille de dialog pour formulaires Inertia. **Pur** (ne compose que les primitives ui).

- **Props :** `open`, `onOpenChange`, `title`, `description`, `submitLabel`, `processing?` (désactive le submit), `contentClassName?` (ex. `max-h-[90vh] overflow-y-auto sm:max-w-3xl` pour les formulaires longs), `onSubmit`, `children`.
- **Structure :** `DialogHeader` (titre + description), `<form className="grid gap-4">` contenant `children` puis `DialogFooter` avec le bouton submit branché sur `processing`.
- **Usage :** toute création/édition en dialog. Les erreurs de champ restent dans `children`, à côté de leur input. Pour les **confirmations** (pas de formulaire), composer directement Dialog + bouton `destructive` (voir `DeleteProjectDialog`).

### FilterBar — story `Components/FilterBar`

Barre de filtres de liste (maquette `.filterbar`). **Présentational et pure** — la page possède l'état d'URL.

- **Props :** `search`, `onSearchChange`, `searchPlaceholder`, `searchAriaLabel`, `searchClassName?` (défaut `w-50`), `visible`, `total`, `children?` (chips de filtres entre la recherche et le compteur).
- **Rendu :** input `type="search"` avec icône `Search` en tête, puis les chips, puis compteur mono `visible / total` calé à droite (`font-mono tabular-nums text-muted-foreground`). `data-test` hooks (`filter-bar`, `filter-search`, `filter-count`) pour la suite E2E.

### FilterSelect — story `Components/FilterSelect`

Select de filtre branché sur l'état d'URL. **Pur.**

- **Props :** `value` (`''` = « tous »), `options: { value, label }[]`, `allLabel`, `onChange` (appelé avec `''` quand on choisit « tous »), `className?` (défaut `w-44`), `testId?`, `ariaLabel?`.
- **Comportement :** sentinelle interne `'all'` ↔ `''` ; le trigger affiche le label de l'option trouvée sinon `allLabel` (`<SelectValue>{selected?.label ?? allLabel}</SelectValue>`). Taille `sm`.

### LabelPicker — story `Components/LabelPicker`

Chips de labels togglables pour les formulaires d'issue/gabarit. **Pur.**

- **Props :** `labels: { id, name }[]`, `selectedIds: string[]`, `onToggle(labelId)`, `idPrefix?` (préfixe d'`id` quand un `<Label>` pointe dessus).
- **Comportement :** chaque label est un `Button size="sm"`, `variant="default"` quand sélectionné, `outline` sinon, avec `aria-pressed`. Liste vide : message `text-muted-foreground` traduit.

### PrioritySelect — story `Components/PrioritySelect`

Select de priorité sur l'échelle fixe. **Pur.**

- **Exports :** `PRIORITIES` (`['none', 'low', 'high', 'urgent']`) et `PrioritySelect({ id?, value, onValueChange })`.
- **Usage :** labels via `t('priority.*')` — jamais de libellés en dur, la priorité est une échelle fixe du modèle.

### LanguageSwitcher — story `Components/LanguageSwitcher` — *couplé à Inertia*

Menu de changement de langue (dropdown dans la sidebar). Importe `router` et `usePage`.

- **Comportement :** optimiste — `i18n.changeLanguage` immédiat, puis `router.patch(route('user.locale.update'), { locale }, { preserveScroll: true })` ; la réponse Inertia resynchronise via l'écouteur global `router.on('success')` de `app.tsx`.
- **Détail :** chaque langue est affichée dans son propre nom (pas de clé i18n) ; la langue courante est marquée d'une puce.

### SettingsShell — story `Components/SettingsShell` — *couplé à Inertia*

Chrome partagé des pages Settings (ADR-4). Importe `Link` et `usePage`.

- **Props :** `title`, `description`, `actions?` (owner-only), `memberReadOnly?` (défaut `true`), `children`.
- **Rendu :** navigation par onglets (Statuts, Membres, Gabarits, Tokens — chaque onglet reste sa route et son responder, `aria-current="page"`), titre + description + actions, puis pour les non-owner : badge « lecture seule » (cadenas) à la place des actions et `Banner variant="info" icon={Lock}` explicatif au-dessus du contenu.
- **Règle :** les sections personnelles (tokens API) passent `memberReadOnly={false}`.

### ProjectMenu — story `Components/ProjectMenu` — *couplé à Inertia*

Menu « ⋯ » des pagebars projet : édition, archivage, archives d'issues, suppression. Importe `router`.

- **Props :** `project: ProjectRef`, `issuesCount`.
- **Comportement :** déclenche `ProjectFormDialog` (édition), `router.post(route('projects.archive', id))` et `router.get(route('projects.archives', id))` directement, et ouvre `DeleteProjectDialog`. L'entrée destructive « Supprimer » reste **désactivée** tant que le projet contient des issues (règle métier), avec le compteur affiché.
- **Composition :** rend le dropdown **et** les deux dialogs qu'il pilote — la page ne branche que `<ProjectMenu project issuesCount />`.

### ProjectViewSwitcher — story `Components/ProjectViewSwitcher` — *couplé à Inertia*

Segment en pilule commutant entre les vues Health et Kanban d'un projet. Importe `Link`.

- **Props :** `projectId`, `active: 'health' | 'board'`.
- **Rendu :** nav `aria-current="page"` avec liens ziggy `projects.health` / `projects.board` ; segment actif `bg-secondary`, inactif `text-muted-foreground hover:bg-accent`.

### FlashBanner — story `Components/FlashBanner` — *couplé à Inertia*

Bandeau de succès piloté par le flash Inertia standard. Importe `usePage`.

- **Comportement :** lit `flash.status` dans les props partagées ; ne rend **rien** quand le flash est vide.
- **Rendu :** `rounded-lg border bg-muted/50 px-4 py-2.5 text-sm` (neutre — le succès est sobre, les alertes passent par `Banner`).

### CreateIssueDialog — story `Components/CreateIssueDialog` — *couplé à Inertia*

Formulaire complet de création d'issue, dans un `FormDialog` large et scrollable. Importe `useForm`.

- **Champs :** gabarit (pré-remplissage côté client : description, priorité, labels — visibles et modifiables avant envoi), titre requis, description via `MarkdownEditor`, projet + statut (grille 2 colonnes), priorité (`PrioritySelect`) + due date, labels (`LabelPicker`).
- **Convention :** clés snake_case (`project_id`, `status_id`, `due_date`, `label_ids`), submit `form.post(route('issues.store'))` avec `onSuccess` fermant le dialog et réinitialisant le form, bouton branché sur `form.processing`.
- **Props :** `open`, `onClose`, `projects`, `statuses`, `labels`, `templates`.

### QuickCreateIssueDialog — story `Components/QuickCreateIssueDialog` — *couplé à Inertia*

Création minimale d'issue (pagebar d'accueil) : un titre et un projet suffisent — statut par défaut et priorité `none` appliqués côté serveur. Importe `useForm`.

- **Champs :** titre (autofocus, requis) + projet. Message de pied rappelant que tout reste éditable après création.
- **Props :** `open`, `onClose`, `projects`.

### ProjectFormDialog — story `Components/ProjectFormDialog` — *couplé à Inertia*

Dialog création/édition de projet ; la prop `project` (ou `null`) sélectionne le mode. Importe `useForm`.

- **Champs :** nom (requis), description (`Textarea`), date cible (`type="date"`).
- **Comportement :** valeurs semées à l'ouverture (`useEffect` — édition réutilise la ligne, création reset) ; submit `form.patch(route('projects.update', id))` en édition, `form.post(route('projects.store'))` en création.
- **Props :** `open`, `project: ProjectRef | null`, `onClose`.

### DeleteProjectDialog — story `Components/DeleteProjectDialog` — *couplé à Inertia*

Dialog de confirmation de suppression de projet. Importe `router`.

- **Props :** `project: (ProjectRef & { health?... }) | null` (`null` = fermé), `onClose`.
- **Comportement :** la suppression est tentée telle quelle ; si l'action la refuse (projet contenant des issues, `onHttpException`), le refus s'affiche en `Banner variant="danger"` avec un bouton « Archiver à la place » (`variant="outline"`). Le bouton de confirmation est `variant="destructive"` ; le bouton secondaire est `ghost`.
- **Pattern :** dialogue de confirmation de référence — actions fantômes à gauche, action destructive à droite dans `DialogFooter`.
