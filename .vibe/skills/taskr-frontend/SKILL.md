---
name: taskr-frontend
description: Conventions for taskr frontend code — Inertia v3 pages, React 19 + TypeScript, useForm/router patterns, shadcn components on Base UI, Tailwind v4 tokens, ziggy named routes. Use for any change under resources/js/ or resources/views/.
---

# Taskr Frontend

Stack: Inertia v3, React 19, TypeScript, Tailwind CSS v4, shadcn components built on `@base-ui/react` (not Radix), ziggy `route()` global, lucide-react icons. No state library, no API layer, no client fetching — Inertia props are the only data source.

## Architecture map

```
resources/js/
├── app.tsx                     # createInertiaApp, glob-registered pages, "- Taskr" title suffix
├── Pages/<Domain>/<Name>.tsx   # default-export page components (Issues/Index, Settings/Statuses, ...)
├── layouts/app-layout.tsx      # header, workspace switcher, nav, main container (max-w-6xl)
├── layouts/guest-layout.tsx    # auth pages
├── components/ui/*.tsx         # shadcn primitives (button, dialog, select, table, ...)
├── types.ts                    # ONLY shared props: AuthUser, WorkspaceSummary, PageSharedProps
└── lib/utils.ts                # re-exports cn from 'cn'
```

Page component names must match the string the backend responder renders: `Inertia::render('Issues/Index')` renders `Pages/Issues/Index.tsx`.

## Hard rules

1. **Pages are default-exported function components** in `resources/js/Pages/<Domain>/<Name>.tsx`, wrapped in `AppLayout` (or `GuestLayout` for auth) with a `<Head title="..." />`. Do not create new layout files.
2. **Props are typed locally per page.** Define `type PageProps = {...}` / interfaces at the top of the file mirroring the responder payload — camelCase keys (`dueDate`, `statusName`), ULID ids as `string`, `User.id` as `number`. Only genuinely cross-page shapes (`auth`, `workspace`, `workspaces`, `flash`) live in `types.ts`. Backend presenter changes require updating the page interface in the same task.
3. **Read data with `usePage<PageProps>().props`.** Shared props in a second call: `const { flash } = usePage<{ flash?: { status?: string } }>().props;`.
4. **Forms use `useForm` from `@inertiajs/react`.** Field names are snake_case to match backend validation keys (`project_id`, `status_id`, `due_date`), even when displayed labels differ. Submit handler: `e.preventDefault()` then `form.post|patch(route('x.store'|'x.update', id), { onSuccess: () => { reset state; form.reset(); } })`. Submit button gets `disabled={form.processing}`.
5. **Field errors:** `{form.errors.field ? <p className="text-sm text-destructive">{form.errors.field}</p> : null}` under the input.
6. **Navigation only via ziggy named routes**: `<Link href={route('issues.show', issue.id)}>`, `router.get/post/delete(route(...))`. Never hardcode URLs. Dedicated verb routes for sub-actions exist (`issues.move`, `issues.parent`, `issues.assignees`, `issues.labels`) — send state-changing POSTs there instead of overloaded PATCHes.
7. **List filters** use `router.get(route('issues.index'), nextFilters, { preserveState: true })`, with `'all'` sentinel mapped to `''` before sending. Filter selects render the selected label via `<SelectValue>{found?.name ?? 'All ...'}</SelectValue>`.
8. **UI primitives come from `@/components/ui/*`.** Import them, never rewrite primitives. Need a new one: add it with the shadcn CLI so it stays Base UI-based and token-driven. `cn()` from `@/lib/utils` (or `cn` package) for conditional classes.
9. **Styling: Tailwind design tokens only** — `text-muted-foreground` for secondary text, `bg-muted/50` for banners, `text-destructive` for danger/overdue, `variant="outline|ghost|secondary"` on Buttons/Badges. No raw hex colors, no new CSS files. Dialogs: `DialogContent` with `DialogHeader/Title/Description`, form inside, actions in `DialogFooter`.
10. **Empty states** get a friendly row/message (`No issues match these filters.`), nullable cells render `—`.

## Style

- 4-space indent in Pages and layouts; shadcn-generated `components/ui` files keep their 2-space style untouched.
- Icons from `lucide-react` with `size-4` sizing conventions already in the ui components.
- One component per page; split only when a page grows past ~450 lines (Show.tsx is the current ceiling — extract shared `UserRef`/label types upward only via `types.ts` when two pages need them).

## Verification

- `npm run build` after structural changes; if the browser shows a stale page or a Vite manifest error, rebuild or run `npm run dev`.
- Backend tests cover payloads (`assertInertia`); there is no frontend test suite — keep prop interfaces exact so the Inertia contract fails loudly in tests, not silently in the UI.
