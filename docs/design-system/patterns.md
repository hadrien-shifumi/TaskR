# Patterns récurrents

Les enchaînements d'interface qui se répètent d'écran en écran dans Taskr. Chaque pattern cite les composants du [catalogue](./components.md) qu'il assemble et donne la forme du code — pas le code complet : la source de vérité reste les pages existantes (`Pages/Issues/Index.tsx`, `Pages/Projects/Index.tsx`).

---

## Pagebar — coquille applicative

Toute page applicative est rendue par `AppLayout` (`layouts/app-layout.tsx`) : sidebar fixe (marque, sélecteur de workspace, navigation, utilisateur) + **pagebar** collante au-dessus du contenu.

- **Props de la pagebar :** `title` (string ou node quand des badges voisinent le titre), `subtitle` (phrase muted à côté), `leading` (contenu **avant** le titre : bouton retour, switcher de vue), `actions` (contrôles de page à droite), `narrow` (largeur de lecture).
- **Comportement :** header `sticky top-0 z-20`, `bg-background/88 backdrop-blur` — le contenu passe dessous en flou. La pagebar n'existe que si au moins un de `title`/`actions`/`leading` est fourni.
- **Largeurs :** contenu dans `max-w-[1280px]` centré, ou `max-w-[820px]` quand `narrow` (settings).

```tsx
<AppLayout
    title={t('issuesPage.title')}
    subtitle={t('issuesPage.subtitle')}
    actions={
        <>
            <IssuesViewSwitcher active={view} filters={filters} />
            <Button size="sm" onClick={() => setCreating(true)}>{t('issues.newIssue')}</Button>
        </>
    }
>
    <Head title={t('issuesPage.title')} />
    …
</AppLayout>
```

Le `<Head title>` porte le titre d'onglet (`app.tsx` ajoute le suffixe « - Taskr »). Navigation active calculée par préfixe d'URL avec `aria-current="page"`.

## États vides et cellules nullables

- **Liste vide** : message amical centré, en deux niveaux — phrase principale `font-medium`, hint `text-sm text-muted-foreground`. Le message **diffère** selon la présence de filtres actifs :

```tsx
<p className="font-medium">{hasActiveFilters ? t('…emptyFiltered') : t('…empty')}</p>
<p className="mt-1 text-sm text-muted-foreground">{hasActiveFilters ? t('…emptyFilteredHint') : t('…emptyHint')}</p>
```

Exemples canoniques : « No issues match these filters » / « Disable a filter or clear the search. » Dans un tableau, l'état vide peut aussi être une `TableRow` unique :

```tsx
<TableCell colSpan={6} className="py-6 text-center text-muted-foreground">{t('projectsPage.empty')}</TableCell>
```

- **Cellules nullables** : toute valeur optionnelle (due date, description, date cible…) rend `—` plutôt que rien — jamais de cellule blanche silencieuse. Côté i18n, la clé `common.none` vaut `—` : ne jamais l'écrire en dur dans une page. Même logique pour `AvatarStack`, qui ne rend rien quand la liste d'assignés est vide.

## Filtres de liste

L'état de filtre **vit dans l'URL** ; la page ne garde aucun état local de filtre (sauf le champ de recherche, débouncé).

1. Mutation d'un filtre : valeur vide ⇒ le paramètre est **supprimé** de l'objet (URLs propres et partageables), puis navigation :

```tsx
const next = { ...filters };
if (value === '') { delete next[key]; } else { next[key] = value; }
router.get(route('issues.index'), next, { preserveState: true });
```

2. Sentinelle `'all'` : les `FilterSelect` mappent l'option « tous » sur `''` avant l'envoi — le backend ne voit jamais `'all'`.
3. Affichage de la valeur : le trigger affiche le label trouvé, sinon le libellé « tous » :

```tsx
<SelectValue>{selected?.label ?? allLabel}</SelectValue>
```

4. Recherche : champ local + debounce ~300 ms avant `setFilter('q', search)` — le paramètre suit la frappe « avec un temps de retard ».

`FilterBar` (recherche + chips + compteur `visible / total`) est purement présentational ; c'est la page qui possède l'URL et lui passe tout.

## Formulaires Inertia

Convention unique, de la création de workspace au commentaire :

- **`useForm` avec clés snake_case** — elles matchent les clés de validation backend (`project_id`, `status_id`, `due_date`, `label_ids`), même quand le label affiché diffère.
- **Submit :** `e.preventDefault()` puis `form.post | patch(route('x.store' | 'x.update', id), { onSuccess: () => { onClose(); form.reset(); } })`. Jamais d'URL en dur : toujours `route()`.
- **Bouton submit :** `disabled={form.processing}` (FormDialog le fait dès qu'on lui passe `processing`).
- **Erreur de champ** sous l'input, avec `aria-invalid` sur le champ pour l'état visuel destructif :

```tsx
<Label htmlFor="issue-title">{t('issues.fields.title')}</Label>
<Input id="issue-title" aria-invalid={form.errors.title ? true : undefined} … />
{form.errors.title ? (
    <p className="text-sm text-destructive">{form.errors.title}</p>
) : null}
```

- **Champs optionnels** : valeurs `''` par défaut, jamais `undefined` dans le form.
- **Props typées localement** par page (`type PageProps = {...}`, clés camelCase reflétant le payload du responder) — seules les props vraiment transverses vivent dans `types.ts`.

## Dialogs — formulaire et confirmation

- **Formulaire** : `FormDialog` encaisse la structure (`DialogHeader` titre + description, `<form>` en `grid gap-4`, footer avec submit branché sur `processing`). Les formulaires longs passent `contentClassName="max-h-[90vh] overflow-y-auto sm:max-w-3xl"`. À l'ouverture en édition, on **sème** les valeurs via `useEffect` (voir `ProjectFormDialog`) — jamais dans le render.
- **Confirmation** : composition directe de `Dialog` — `DialogFooter` avec bouton secondaire `ghost` à gauche et action `destructive` à droite. Voir `DeleteProjectDialog` : la protection métier (suppression refusée si issues) est tentée puis son refus s'affiche **dans** le dialog en `Banner variant="danger"`, avec l'alternative (« Archiver ») proposée en `variant="outline"`.
- **Fermeture :** backdrop non-cliquable par défaut (`disablePointerDismissal`) — seule la croix ou Échap ferme, pour ne pas perdre une saisie. `onOpenChange` ne fait que replier vers le `onClose` de la page.

## Badges sémantiques et avatars

La couleur d'un badge ne vient **jamais** du nom affiché (libre) mais d'un attribut sémantique du modèle. Les tons complets sont dans la fiche [IssueBadges](./components.md#issuebadges--story-componentsissuebadges) ; en résumé :

| Dimension | Source | Tableau de tons |
|---|---|---|
| Statut | `category` (fixe) | `in_progress` → info · `done` → success · autres → muted |
| Priorité | échelle fixe | `urgent` → destructive · `high` → warning · `low` → info · `none` → muted |
| Santé | projection calculée | `ok` → success · `at-risk` → warning · `late` → destructive · `null` → muted |

- Toujours `Badge variant="secondary" className="rounded-full bg-x/10 text-x"`, point `size-1.5 bg-current` sauf sur les cas « none / pas de projection ».
- **Avatars :** `Avatar` (initiales via `initials()`, pastille `bg-primary/20 text-primary`) et `AvatarStack` pour les assignés multiples — chevauchement `-ml-1.75`, rendu nul si vide.

```tsx
<StatusBadge name={issue.statusName} category={issue.statusCategory} />
<AvatarStack people={issue.assignees} />
```

## Bandeaux

Deux bandeaux, deux usages :

- **`Banner`** (info / warning / danger) : message **persistant** lié à l'état de la page — lecture seule member, avertissement, refus métier. Icône par défaut de la variante, remplaçable (`icon={Lock}`) ou retirée (`icon={null}`) ; `role="alert"` quand le message résulte d'une action.
- **`FlashBanner`** : message de **succès éphémère** lu depuis le flash Inertia standard (`usePage().props.flash?.status`) ; ne rend rien tant que le flash est vide. Il se place en tête de contenu, juste sous la pagebar :

```tsx
<FlashBanner />
```

Le succès est volontairement neutre (`bg-muted/50`) — la couleur d'alerte reste réservée à `Banner`.

## Tableaux de liste

Structure commune à toutes les listes (issues, projets, time entries) :

1. **Enveloppe** : `<div className="overflow-hidden rounded-xl border bg-card">` autour de `Table` (coins arrondis, le conteneur interne gère le scroll horizontal).
2. **En-tête** : `TableHeader` > `TableRow` > `TableHead`, largeurs en classes utilitaires (`w-[30%]`), colonne d'actions réduite (`w-10`).
3. **Corps** : une `TableRow` par entité, extraite dans un composant de ligne (`IssueTableRow`, `ProjectTableRow`) — la page reste lisible. Survol `bg-muted/50` natif.
4. **Actions par ligne** : menu `ProjectMenu` (ou dropdown équivalent) dans la dernière cellule — pas de boutons étalés dans la ligne.
5. **Vide** : ligne unique `colSpan` ou bloc d'état vide sous le tableau (voir plus haut) ; cellules nullables en `—`.

```tsx
<div className="overflow-hidden rounded-xl border bg-card">
    <Table>
        <TableHeader>…</TableHeader>
        <TableBody>
            {rows.map((row) => <Row key={row.id} row={row} />)}
        </TableBody>
    </Table>
</div>
```
